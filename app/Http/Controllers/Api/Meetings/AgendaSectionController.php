<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Http\Controllers\Controller;
use App\Models\AgendaSection;
use App\Models\DiscussionPoint;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Services\ActivityLogService;
use App\Services\TessaAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AgendaSectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetingId = trim($request->query('meeting_id', ''));
        $weekKey = $this->requireWeekKey($request->query('week_key', ''));
        if ($meetingId === '') {
            return response()->json(['error' => 'meeting_id is required'], 422);
        }

        $sections = AgendaSection::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('discussionPoints')
            ->get()
            ->map(fn ($s) => $this->normalizeSection($s));

        $unsectioned = DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->whereNull('section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => $this->normalizePoint($p));

        if ($sections->isEmpty() && $unsectioned->isEmpty()) {
            $this->seedFromTemplate($meetingId, $weekKey);
            $sections = AgendaSection::where('meeting_id', $meetingId)
                ->where('week_key', $weekKey)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->with('discussionPoints')
                ->get()
                ->map(fn ($s) => $this->normalizeSection($s));
            $unsectioned = DiscussionPoint::where('meeting_id', $meetingId)
                ->where('week_key', $weekKey)
                ->whereNull('section_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($p) => $this->normalizePoint($p));
        }

        return response()->json([
            'ok' => true,
            'sections' => $sections,
            'unsectioned' => $unsectioned,
        ]);
    }

    private function seedFromTemplate(string $meetingId, string $weekKey): void
    {
        $meeting = Meeting::where('meeting_key', $meetingId)->first()
            ?? Meeting::where('meeting_key', preg_replace('/-(mon|tue|wed|thu|fri)$/i', '', $meetingId))->first();
        if (! $meeting || ! $meeting->agenda_template_id) {
            return;
        }
        $template = $meeting->agendaTemplate;
        if (! $template) {
            return;
        }
        $items = $template->items()->orderBy('sort_order')->orderBy('id')->get();
        $currentSection = null;
        $sectionSort = 0;
        if ($items->isEmpty()) {
            return;
        }
        foreach ($items as $item) {
            if ($item->point_question === null || $item->point_question === '') {
                $currentSection = AgendaSection::create([
                    'meeting_id' => $meetingId,
                    'week_key' => $weekKey,
                    'title' => $item->section_title ?: 'Discussion',
                    'sort_order' => ++$sectionSort,
                ]);
            } else {
                if (! $currentSection) {
                    $currentSection = AgendaSection::create([
                        'meeting_id' => $meetingId,
                        'week_key' => $weekKey,
                        'title' => 'Discussion',
                        'sort_order' => ++$sectionSort,
                    ]);
                }
                $pointSort = (int) DiscussionPoint::where('section_id', $currentSection->id)->max('sort_order') + 1;
                DiscussionPoint::create([
                    'meeting_id' => $meetingId,
                    'week_key' => $weekKey,
                    'question' => $item->point_question,
                    'answer' => '',
                    'sort_order' => $pointSort,
                    'section_id' => $currentSection->id,
                ]);
            }
        }
    }

    public function store(Request $request): JsonResponse
    {
        $action = $request->input('action', '');

        if ($action === 'add_section') {
            return $this->addSection($request);
        }
        if ($action === 'rename_section') {
            return $this->renameSection($request);
        }
        if ($action === 'delete_section') {
            return $this->deleteSection($request);
        }
        if ($action === 'add_point') {
            return $this->addPoint($request);
        }
        if ($action === 'update_point') {
            return $this->updatePoint($request);
        }
        if ($action === 'delete_point') {
            return $this->deletePoint($request);
        }
        if ($action === 'clear_agenda') {
            return $this->clearAgenda($request);
        }
        if ($action === 'auto_fill') {
            return $this->autoFillFromNotes($request);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function addSection(Request $request): JsonResponse
    {
        $meetingId = trim($request->input('meetingId', ''));
        $weekKey = $this->requireWeekKey($request->input('weekKey', ''));
        $title = trim($request->input('title', ''));
        if ($meetingId === '' || $title === '') {
            return response()->json(['error' => 'meetingId and title are required'], 422);
        }

        $nextSort = (int) AgendaSection::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)->max('sort_order') + 1;

        $section = AgendaSection::create([
            'meeting_id' => $meetingId,
            'week_key' => $weekKey,
            'title' => $title,
            'sort_order' => $nextSort,
        ]);

        ActivityLogService::log($request->user()->id, 'agenda_section_added', "{$request->user()->name} added agenda section: {$title}", 'agenda_section', $section->id, ['meeting_id' => $meetingId, 'title' => $title]);

        return response()->json(['ok' => true, 'section' => $this->normalizeSection($section->load('discussionPoints'))], 201);
    }

    private function renameSection(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $title = trim($request->input('title', ''));
        if ($id <= 0 || $title === '') {
            return response()->json(['error' => 'id and title are required'], 422);
        }
        $section = AgendaSection::find($id);
        if (! $section) {
            return response()->json(['error' => 'Section not found'], 404);
        }
        $section->update(['title' => $title]);
        ActivityLogService::log(request()->user()->id, 'agenda_section_renamed', request()->user()->name." renamed agenda section: {$title}", 'agenda_section', $id);

        return response()->json(['ok' => true]);
    }

    private function deleteSection(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        $section = AgendaSection::find($id);
        if (! $section) {
            return response()->json(['error' => 'Section not found'], 404);
        }
        ActivityLogService::log(request()->user()->id, 'agenda_section_deleted', request()->user()->name." deleted agenda section #{$id}", 'agenda_section', $id);
        DiscussionPoint::where('section_id', $id)->update(['section_id' => null]);
        $section->delete();

        return response()->json(['ok' => true]);
    }

    private function addPoint(Request $request): JsonResponse
    {
        $sectionId = (int) $request->input('sectionId', 0);
        $question = trim($request->input('question', ''));
        if ($sectionId <= 0 || $question === '') {
            return response()->json(['error' => 'sectionId and question are required'], 422);
        }
        $section = AgendaSection::find($sectionId);
        if (! $section) {
            return response()->json(['error' => 'Section not found'], 404);
        }

        $nextSort = (int) DiscussionPoint::where('section_id', $sectionId)->max('sort_order') + 1;

        $point = DiscussionPoint::create([
            'meeting_id' => $section->meeting_id,
            'week_key' => $section->week_key,
            'question' => $question,
            'answer' => '',
            'sort_order' => $nextSort,
            'section_id' => $sectionId,
        ]);

        ActivityLogService::log(request()->user()->id, 'agenda_point_added', request()->user()->name.' added agenda point', 'discussion_point', $point->id, ['section_id' => $sectionId]);

        return response()->json(['ok' => true, 'item' => $this->normalizePoint($point)], 201);
    }

    private function updatePoint(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        $point = DiscussionPoint::find($id);
        if (! $point) {
            return response()->json(['error' => 'Point not found'], 404);
        }
        if ($request->has('answer')) {
            $point->answer = $request->input('answer', '');
        }
        if ($request->has('question')) {
            $point->question = trim($request->input('question', ''));
        }
        $point->save();
        ActivityLogService::log(request()->user()->id, 'agenda_point_updated', request()->user()->name." updated agenda point #{$id}", 'discussion_point', $id);

        return response()->json(['ok' => true]);
    }

    private function deletePoint(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        ActivityLogService::log(request()->user()->id, 'agenda_point_deleted', request()->user()->name." deleted agenda point #{$id}", 'discussion_point', $id);
        DiscussionPoint::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    private function clearAgenda(Request $request): JsonResponse
    {
        $meetingId = trim($request->input('meetingId', ''));
        $weekKey = $this->requireWeekKey($request->input('weekKey', ''));
        if ($meetingId === '') {
            return response()->json(['error' => 'meetingId is required'], 422);
        }

        DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->delete();
        AgendaSection::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->delete();

        ActivityLogService::log(request()->user()->id, 'agenda_cleared', request()->user()->name." cleared full agenda for meeting {$meetingId}", null, null, ['meeting_id' => $meetingId, 'week_key' => $weekKey]);

        return response()->json(['ok' => true]);
    }

    private function autoFillFromNotes(Request $request): JsonResponse
    {
        $meetingId = trim($request->input('meetingId', ''));
        $weekKey = $this->requireWeekKey($request->input('weekKey', ''));
        if ($meetingId === '') {
            return response()->json(['error' => 'meetingId is required'], 422);
        }

        $note = MeetingNote::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->first();

        if (! $note || trim((string) $note->content) === '') {
            return response()->json(['error' => 'No notes found to generate answers from'], 422);
        }

        $points = DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->get();

        // The agenda is normally seeded from the meeting's template the first
        // time its agenda tab is viewed (index). But notes can be saved before
        // that ever happens — the save flow triggers auto_fill directly — which
        // left zero questions to fill, so the AI extraction silently no-op'd.
        // Seed from the template here too, then re-query, so saving minutes
        // reliably extracts answers even on the very first interaction.
        if ($points->isEmpty()) {
            $this->seedFromTemplate($meetingId, $weekKey);
            $points = DiscussionPoint::where('meeting_id', $meetingId)
                ->where('week_key', $weekKey)
                ->get();
        }

        if ($points->isEmpty()) {
            return response()->json(['ok' => true, 'filled' => 0, 'message' => 'No agenda questions to fill']);
        }

        // Only (re)generate answers for points still blank or carrying the stale
        // "Not discussed" sentinel — preserve existing real answers (so a PARTIALLY
        // filled agenda is completed, not clobbered) and skip the AI call entirely
        // when every point is already answered.
        $toFill = $points->filter(function ($p) {
            $a = trim((string) $p->answer);

            return $a === '' || stripos($a, 'not discussed') !== false;
        })->values();

        if ($toFill->isEmpty()) {
            return response()->json(['ok' => true, 'filled' => 0, 'message' => 'Agenda already filled']);
        }

        $questions = $toFill->map(fn ($p) => ['id' => $p->id, 'question' => $p->question])->values()->toArray();

        try {
            $aiService = new TessaAIService;
            $answers = $aiService->fillAgendaFromNotes($note->content, $questions);
        } catch (\Throwable $e) {
            Log::error('AgendaSectionController: auto_fill AI call failed', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'AI service unavailable, please try again'], 500);
        }

        $filled = 0;
        foreach ($toFill as $point) {
            if (isset($answers[$point->id]) && trim((string) $answers[$point->id]) !== '') {
                $point->answer = $answers[$point->id];
                $point->save();
                $filled++;
            }
        }

        ActivityLogService::log(
            $request->user()->id,
            'agenda_auto_filled',
            "{$request->user()->name} auto-filled {$filled} agenda answers from notes for {$meetingId}",
            null,
            null,
            ['meeting_id' => $meetingId, 'week_key' => $weekKey, 'filled' => $filled]
        );

        $sections = AgendaSection::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('discussionPoints')
            ->get()
            ->map(fn ($s) => $this->normalizeSection($s));

        $unsectioned = DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->whereNull('section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => $this->normalizePoint($p));

        return response()->json([
            'ok' => true,
            'filled' => $filled,
            'sections' => $sections,
            'unsectioned' => $unsectioned,
        ]);
    }

    private function normalizeSection(AgendaSection $s): array
    {
        return [
            'id' => $s->id,
            'meetingId' => $s->meeting_id,
            'weekKey' => $s->week_key?->format('Y-m-d'),
            'title' => $s->title,
            'sortOrder' => $s->sort_order,
            'points' => $s->discussionPoints->map(fn ($p) => $this->normalizePoint($p))->values()->toArray(),
        ];
    }

    private function normalizePoint(DiscussionPoint $p): array
    {
        return [
            'id' => $p->id,
            'meetingId' => $p->meeting_id,
            'weekKey' => $p->week_key?->format('Y-m-d'),
            'question' => $p->question,
            'answer' => $p->answer ?? '',
            'sortOrder' => $p->sort_order,
            'sectionId' => $p->section_id,
        ];
    }

    private function requireWeekKey(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            abort(422, 'Invalid week_key format');
        }

        return trim($value);
    }
}
