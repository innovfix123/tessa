<?php

namespace App\Http\Controllers\Api\Meetings;

use App\Http\Controllers\Controller;
use App\Models\DiscussionPoint;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscussionPointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetingId = trim($request->query('meeting_id', ''));
        $weekKey = $this->requireWeekKey($request->query('week_key', ''));
        if ($meetingId === '') {
            return response()->json(['error' => 'meeting_id is required'], 422);
        }

        $items = DiscussionPoint::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->whereNull('section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => $this->normalizePoint($p));

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $action = $request->input('action', '');

        if ($action === 'add') {
            return $this->add($request);
        }
        if ($action === 'update') {
            return $this->updatePoint($request);
        }
        if ($action === 'delete') {
            return $this->deletePoint($request);
        }

        return response()->json(['error' => 'Unknown action'], 404);
    }

    private function add(Request $request): JsonResponse
    {
        $meetingId = trim($request->input('meetingId', ''));
        $weekKey = $this->requireWeekKey($request->input('weekKey', ''));
        $question = trim($request->input('question', ''));
        if ($meetingId === '' || $question === '') {
            return response()->json(['error' => 'meetingId and question are required'], 422);
        }

        $nextSort = (int) DiscussionPoint::where('meeting_id', $meetingId)->where('week_key', $weekKey)->max('sort_order') + 1;

        $point = DiscussionPoint::create([
            'meeting_id' => $meetingId,
            'week_key' => $weekKey,
            'question' => $question,
            'answer' => '',
            'sort_order' => $nextSort,
        ]);

        ActivityLogService::log($request->user()->id, 'discussion_point_added', "{$request->user()->name} added discussion point", 'discussion_point', $point->id, ['meeting_id' => $meetingId]);

        return response()->json(['ok' => true, 'item' => $this->normalizePoint($point)], 201);
    }

    private function updatePoint(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $answer = $request->input('answer', '');
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }

        $point = DiscussionPoint::find($id);
        if (! $point) {
            return response()->json(['error' => 'Discussion point not found'], 404);
        }
        $point->update(['answer' => $answer]);
        ActivityLogService::log(request()->user()->id, 'discussion_point_answered', request()->user()->name." answered discussion point #{$id}", 'discussion_point', $id);

        return response()->json(['ok' => true]);
    }

    private function deletePoint(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }
        ActivityLogService::log(request()->user()->id, 'discussion_point_deleted', request()->user()->name." deleted discussion point #{$id}", 'discussion_point', $id);
        DiscussionPoint::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    private function requireWeekKey(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            abort(422, 'Invalid week_key format');
        }

        return trim($value);
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
        ];
    }
}
