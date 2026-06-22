<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskMessage;
use App\Models\TaskParticipant;
use App\Models\TessaTask;
use App\Models\User;
use App\Services\TessaAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskThreadController extends Controller
{
    use TaskAuthorization;
    public function messages(Request $request, TessaTask $task): JsonResponse
    {
        $user = $request->user();
        if (! $this->canAccessTask($task, $user->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Get user's last_read_at before updating it
        $participant = TaskParticipant::where('task_id', $task->id)->where('user_id', $user->id)->first();
        $lastReadAt = $participant?->last_read_at;

        // Mark as read now
        if ($participant) {
            $participant->update(['last_read_at' => now()]);
        }

        $messages = $task->messages()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'user_name' => $m->user?->name ?? 'Unknown',
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
                'is_unread' => $lastReadAt ? $m->created_at?->gt($lastReadAt) : false,
            ]);

        $participants = $task->participants()
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => [
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name ?? 'Unknown',
                'role' => $p->role,
            ]);

        return response()->json([
            'ok' => true,
            'messages' => $messages,
            'participants' => $participants,
        ]);
    }

    public function postMessage(Request $request, TessaTask $task): JsonResponse
    {
        $user = $request->user();
        if (! $this->canAccessTask($task, $user->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $content = trim($request->input('content', ''));
        if ($content === '') {
            return response()->json(['error' => 'Message cannot be empty'], 422);
        }

        $message = TaskMessage::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'content' => $content,
            'created_at' => now(),
        ]);

        // Run AI analysis on the thread conversation (generates summary, detects blockers)
        $this->analyzeThread($task, $user);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'user_id' => $message->user_id,
                'user_name' => $user->name,
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
                'time_ago' => $message->created_at->diffForHumans(),
            ],
            'ai_summary' => $task->fresh()->ai_summary,
            'task_status' => $task->fresh()->status,
            'task_deadline' => $task->fresh()->deadline?->toIso8601String(),
        ]);
    }

    public function invite(Request $request, TessaTask $task): JsonResponse
    {
        $user = $request->user();
        if (! $this->canAccessTask($task, $user->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0 || ! User::find($userId)) {
            return response()->json(['error' => 'Invalid user'], 422);
        }

        TaskParticipant::firstOrCreate(
            ['task_id' => $task->id, 'user_id' => $userId],
            ['role' => 'invited']
        );

        return response()->json(['ok' => true]);
    }

    private function analyzeThread(TessaTask $task, $currentUser): void
    {
        try {
            $messages = $task->messages()->with('user:id,name')->orderBy('created_at')->get();
            if ($messages->count() < 1) {
                return;
            }

            $conversation = $messages->map(fn ($m) => $m->user?->name . ': ' . $m->content)->implode("\n");

            $taskContext = "Task: {$task->title}\n" .
                "Assigned by: " . ($task->assignedBy?->name ?? '?') . "\n" .
                "Assigned to: " . ($task->assignedTo?->name ?? '?') . "\n" .
                "Status: {$task->status}\n" .
                "Deadline: " . ($task->deadline ? $task->deadline->format('Y-m-d H:i') : 'none') . "\n" .
                "Current date: " . now()->format('Y-m-d H:i');

            $systemPrompt = 'You analyze task thread conversations and return ONLY valid JSON (no markdown, no explanation). Based on the conversation, determine:
1. A 1-line summary of the thread status (max 80 chars)
2. Whether the task status should change (null if no change, or "in_progress" or "completed")
3. Whether the deadline should be extended (null if no change, or a new datetime like "2026-03-31 18:00:00")
4. Whether there is a blocker: "on_track", "blocked", or null (no change)
5. If blocked, a short description of the blocker (max 150 chars), else null

Return JSON: {"summary": "...", "status_update": null, "deadline_update": null, "blocker_status": null, "blocker_note": null}

Rules:
- If someone says they need more time/extension, set deadline_update to the mentioned date
- If someone says it\'s done/completed/finished, set status_update to "completed"
- If someone says they started/working on it, set status_update to "in_progress"
- If someone reports a blocker/issue/stuck/waiting on someone/dependency, set blocker_status to "blocked" and blocker_note to describe it
- If someone says "on track", "going well", "no issues", "all good", set blocker_status to "on_track"
- Only update if the conversation clearly indicates it — don\'t guess
- Summary should capture the latest state of the conversation';

            $ai = app(TessaAIService::class);
            $result = $ai->quickAi($systemPrompt, "TASK:\n{$taskContext}\n\nCONVERSATION:\n{$conversation}");

            $parsed = json_decode($result, true);
            if (! is_array($parsed)) {
                return;
            }

            $updates = [];

            if (! empty($parsed['summary'])) {
                $updates['ai_summary'] = mb_substr($parsed['summary'], 0, 255);
            }

            if (! empty($parsed['status_update']) && in_array($parsed['status_update'], ['in_progress', 'completed'])) {
                $updates['status'] = $parsed['status_update'];
                if ($parsed['status_update'] === 'completed' && ! $task->completed_at) {
                    $updates['completed_at'] = now();
                }
            }

            if (! empty($parsed['deadline_update'])) {
                try {
                    $updates['deadline'] = DateHelper::parse($parsed['deadline_update']);
                } catch (\Exception $e) {
                    // ignore invalid date
                }
            }

            if (! empty($parsed['blocker_status']) && in_array($parsed['blocker_status'], ['on_track', 'blocked'])) {
                $updates['blocker_status'] = $parsed['blocker_status'];
                $updates['last_checkin_at'] = now();
                if ($parsed['blocker_status'] === 'blocked' && ! empty($parsed['blocker_note'])) {
                    $updates['blocker_note'] = mb_substr($parsed['blocker_note'], 0, 500);
                    // Alert the assigner about the blocker
                    try {
                        $slack = new \App\Services\SlackService;
                        $assignerName = $task->assignedBy?->name;
                        $assigneeName = $task->assignedTo?->name ?? 'Assignee';
                        if ($assignerName) {
                            $slackId = $slack->getUserIdByName($assignerName);
                            if ($slackId) {
                                $slack->sendDirectMessage($slackId,
                                    "Blocker on *{$task->title}*: {$assigneeName} reports — {$parsed['blocker_note']}");
                            }
                        }
                    } catch (\Throwable $e) {
                        // don't fail the whole analysis for a notification
                    }
                }
                if ($parsed['blocker_status'] === 'on_track') {
                    $updates['blocker_note'] = null;
                }
            }

            if (! empty($updates)) {
                $task->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('TaskThread AI analysis failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);
        }
    }
}
