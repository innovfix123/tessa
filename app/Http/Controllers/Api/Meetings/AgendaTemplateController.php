<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Http\Controllers\Controller;
use App\Models\AgendaTemplate;
use App\Models\AgendaTemplateItem;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgendaTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = AgendaTemplate::with('items')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => $this->normalizeTemplate($t));

        return response()->json(['ok' => true, 'items' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! ProjectRoleService::canManageTemplates($request->user()->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $action = $request->input('action', '');

        if ($action === 'create_template') {
            return $this->createTemplate($request);
        }
        if ($action === 'update_template') {
            return $this->updateTemplate($request);
        }
        if ($action === 'delete_template') {
            return $this->deleteTemplate($request);
        }
        if ($action === 'add_item') {
            return $this->addItem($request);
        }
        if ($action === 'update_item') {
            return $this->updateItem($request);
        }
        if ($action === 'delete_item') {
            return $this->deleteItem($request);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function createTemplate(Request $request): JsonResponse
    {
        $name = trim($request->input('name', ''));
        if ($name === '') {
            return response()->json(['error' => 'name is required'], 422);
        }

        $template = AgendaTemplate::create([
            'name' => $name,
            'created_by' => $request->user()->id,
        ]);

        ActivityLogService::log($request->user()->id, 'template_created', "{$request->user()->name} created template: {$name}", 'agenda_template', $template->id, ['name' => $name]);

        return response()->json(['ok' => true, 'item' => $this->normalizeTemplate($template->load('items'))], 201);
    }

    private function updateTemplate(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $name = trim($request->input('name', ''));
        if ($id <= 0 || $name === '') {
            return response()->json(['error' => 'id and name are required'], 422);
        }
        $template = AgendaTemplate::find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }
        $template->update(['name' => $name]);
        ActivityLogService::log($request->user()->id, 'template_updated', "{$request->user()->name} renamed template to: {$name}", 'agenda_template', $id);

        return response()->json(['ok' => true]);
    }

    private function deleteTemplate(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        $template = AgendaTemplate::find($id);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }
        ActivityLogService::log($request->user()->id, 'template_deleted', "{$request->user()->name} deleted template #{$id}", 'agenda_template', $id);
        $template->delete();

        return response()->json(['ok' => true]);
    }

    private function addItem(Request $request): JsonResponse
    {
        $templateId = (int) $request->input('templateId', 0);
        $sectionTitle = trim($request->input('sectionTitle', ''));
        $pointQuestion = $request->has('pointQuestion') ? trim($request->input('pointQuestion', '')) : null;
        $afterItemId = (int) $request->input('afterItemId', 0);
        if ($templateId <= 0) {
            return response()->json(['error' => 'templateId is required'], 422);
        }
        if ($sectionTitle === '' && ($pointQuestion === null || $pointQuestion === '')) {
            return response()->json(['error' => 'sectionTitle or pointQuestion is required'], 422);
        }

        $template = AgendaTemplate::find($templateId);
        if (! $template) {
            return response()->json(['error' => 'Template not found'], 404);
        }

        $nextSort = (int) AgendaTemplateItem::where('template_id', $templateId)->max('sort_order') + 1;

        if ($afterItemId > 0) {
            $afterItem = AgendaTemplateItem::where('template_id', $templateId)->find($afterItemId);
            if ($afterItem) {
                $insertSort = $afterItem->sort_order + 1;
                AgendaTemplateItem::where('template_id', $templateId)
                    ->where('sort_order', '>=', $insertSort)
                    ->increment('sort_order');
                $nextSort = $insertSort;
            }
        }

        $item = AgendaTemplateItem::create([
            'template_id' => $templateId,
            'section_title' => $sectionTitle,
            'point_question' => $pointQuestion ?: null,
            'sort_order' => $nextSort,
        ]);

        ActivityLogService::log($request->user()->id, 'template_item_added', "{$request->user()->name} added item to template #{$templateId}", 'agenda_template_item', $item->id, ['template_id' => $templateId]);

        return response()->json(['ok' => true, 'item' => $this->normalizeItem($item)], 201);
    }

    private function updateItem(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        $item = AgendaTemplateItem::find($id);
        if (! $item) {
            return response()->json(['error' => 'Item not found'], 404);
        }
        if ($request->has('sectionTitle')) {
            $item->section_title = trim($request->input('sectionTitle', ''));
        }
        if ($request->has('pointQuestion')) {
            $item->point_question = trim($request->input('pointQuestion', '')) ?: null;
        }
        $item->save();
        ActivityLogService::log($request->user()->id, 'template_item_updated', "{$request->user()->name} updated template item #{$id}", 'agenda_template_item', $id);

        return response()->json(['ok' => true]);
    }

    private function deleteItem(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        ActivityLogService::log($request->user()->id, 'template_item_deleted', "{$request->user()->name} deleted template item #{$id}", 'agenda_template_item', $id);
        AgendaTemplateItem::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    private function normalizeTemplate(AgendaTemplate $t): array
    {
        $items = $t->items->map(fn ($i) => $this->normalizeItem($i))->values()->toArray();

        return [
            'id' => $t->id,
            'name' => $t->name,
            'items' => $items,
        ];
    }

    private function normalizeItem(AgendaTemplateItem $i): array
    {
        return [
            'id' => $i->id,
            'templateId' => $i->template_id,
            'sectionTitle' => $i->section_title,
            'pointQuestion' => $i->point_question,
            'sortOrder' => $i->sort_order,
        ];
    }
}
