<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\ScriptGeneration;
use App\Models\ScriptLibraryItem;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use App\Services\ScriptGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScriptGenerationController extends Controller
{
    public function __construct(
        private ScriptGenerationService $scriptGenerationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'scripts')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canGenerateScripts($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $scope = $request->query('scope', 'history');
        if ($scope === 'library') {
            $q = ScriptLibraryItem::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id');

            if ($request->filled('language')) {
                $q->where('language', $request->query('language'));
            }
            if ($request->filled('category')) {
                $q->where('category', $request->query('category'));
            }
            $items = $q->limit(200)->get()->map(fn (ScriptLibraryItem $i) => [
                'id' => $i->id,
                'body' => $i->body,
                'language' => $i->language,
                'category' => $i->category,
                'topic' => $i->topic,
                'created_at' => $i->created_at?->toIso8601String(),
            ]);

            return response()->json(['ok' => true, 'items' => $items]);
        }

        $generations = ScriptGeneration::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ScriptGeneration $g) => [
                'id' => $g->id,
                'language' => $g->language,
                'category' => $g->category,
                'topic' => $g->topic,
                'creative_brief' => $g->creative_brief,
                'requested_count' => $g->requested_count,
                'scripts' => $g->scripts,
                'created_at' => $g->created_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'generations' => $generations]);
    }

    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'scripts')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canGenerateScripts($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'language' => ['required', 'string', Rule::in(ScriptGenerationService::validLanguages())],
            'category' => ['required', 'string', Rule::in(ScriptGenerationService::validCategories())],
            'creative_brief' => ['nullable', 'string', 'max:2000'],
            'count' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $scripts = $this->scriptGenerationService->generateScripts(
            $validated['language'],
            $validated['category'],
            $validated['creative_brief'] ?? null,
            (int) $validated['count'],
        );

        if ($scripts === []) {
            return response()->json([
                'ok' => false,
                'error' => 'Could not generate scripts. Check API configuration or try again.',
            ], 502);
        }

        $record = ScriptGeneration::create([
            'user_id' => $user->id,
            'language' => $validated['language'],
            'category' => $validated['category'],
            'topic' => 'general',
            'creative_brief' => $validated['creative_brief'] ?? null,
            'requested_count' => (int) $validated['count'],
            'scripts' => $scripts,
        ]);

        $scriptCount = count($scripts);
        ActivityLogService::log($user->id, 'scripts_generated', "{$user->name} generated {$scriptCount} scripts ({$validated['language']}, {$validated['category']})", 'script_generation', $record->id, ['language' => $validated['language'], 'category' => $validated['category'], 'count' => $scriptCount]);

        return response()->json([
            'ok' => true,
            'generation' => [
                'id' => $record->id,
                'language' => $record->language,
                'category' => $record->category,
                'topic' => $record->topic,
                'creative_brief' => $record->creative_brief,
                'requested_count' => $record->requested_count,
                'scripts' => $record->scripts,
                'created_at' => $record->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function saveLibrary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::canGenerateScripts($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'language' => ['required', 'string', Rule::in(ScriptGenerationService::validLanguages())],
            'category' => ['required', 'string', Rule::in(ScriptGenerationService::validCategories())],
            'script_generation_id' => ['nullable', 'integer', 'exists:script_generations,id'],
        ]);

        if (! empty($validated['script_generation_id'])) {
            $gen = ScriptGeneration::find($validated['script_generation_id']);
            if (! $gen || $gen->user_id !== $user->id) {
                return response()->json(['error' => 'Invalid generation'], 422);
            }
        }

        $item = ScriptLibraryItem::create([
            'user_id' => $user->id,
            'script_generation_id' => $validated['script_generation_id'] ?? null,
            'body' => $validated['body'],
            'language' => $validated['language'],
            'category' => $validated['category'],
            'topic' => 'general',
        ]);

        ActivityLogService::log($user->id, 'script_saved_to_library', "{$user->name} saved script to library ({$validated['language']}, {$validated['category']})", 'script_library_item', $item->id, ['language' => $validated['language'], 'category' => $validated['category']]);

        return response()->json([
            'ok' => true,
            'item' => [
                'id' => $item->id,
                'body' => $item->body,
                'language' => $item->language,
                'category' => $item->category,
                'topic' => $item->topic,
                'created_at' => $item->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function destroyLibrary(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::canGenerateScripts($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $item = ScriptLibraryItem::where('id', $id)->where('user_id', $user->id)->first();
        if (! $item) {
            return response()->json(['error' => 'Not found'], 404);
        }
        ActivityLogService::log($user->id, 'script_deleted_from_library', "{$user->name} deleted script from library", 'script_library_item', $id);
        $item->delete();

        return response()->json(['ok' => true]);
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::hasFeature($user->role, 'scripts')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $totalGenerations = ScriptGeneration::count();
        $totalScripts = (int) ScriptGeneration::query()
            ->get()
            ->sum(fn (ScriptGeneration $g) => count($g->scripts ?? []));

        $byLanguage = ScriptGeneration::query()
            ->select('language', DB::raw('COUNT(*) as generations'))
            ->groupBy('language')
            ->orderByDesc('generations')
            ->get()
            ->map(fn ($r) => ['language' => $r->language, 'label' => ScriptGenerationService::languageLabel($r->language), 'generations' => (int) $r->generations]);

        $byCategory = ScriptGeneration::query()
            ->select('category', DB::raw('COUNT(*) as generations'))
            ->groupBy('category')
            ->orderByDesc('generations')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'generations' => (int) $r->generations]);

        $byUser = ScriptGeneration::query()
            ->select('user_id', DB::raw('COUNT(*) as generations'))
            ->groupBy('user_id')
            ->orderByDesc('generations')
            ->limit(30)
            ->get();

        $userIds = $byUser->pluck('user_id')->all();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');
        $byUserOut = $byUser->map(fn ($r) => [
            'user_id' => (int) $r->user_id,
            'name' => $names[$r->user_id] ?? ('User #'.$r->user_id),
            'generations' => (int) $r->generations,
        ]);

        $recent = ScriptGeneration::query()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (ScriptGeneration $g) => [
                'id' => $g->id,
                'user_name' => $g->user?->name ?? '',
                'language' => $g->language,
                'category' => $g->category,
                'topic' => $g->topic,
                'script_count' => is_array($g->scripts) ? count($g->scripts) : 0,
                'created_at' => $g->created_at?->toIso8601String(),
            ]);

        $libraryTotal = ScriptLibraryItem::count();

        return response()->json([
            'ok' => true,
            'stats' => [
                'total_generations' => $totalGenerations,
                'total_scripts_generated' => $totalScripts,
                'library_items_saved' => $libraryTotal,
                'by_language' => $byLanguage,
                'by_category' => $byCategory,
                'by_user' => $byUserOut,
                'recent' => $recent,
            ],
        ]);
    }
}
