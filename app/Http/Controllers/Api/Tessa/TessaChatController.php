<?php

namespace App\Http\Controllers\Api\Tessa;

use App\Http\Controllers\Controller;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\TessaChat;
use App\Models\TessaMessage;
use App\Services\SignoffStatusService;
use App\Services\LeaveService;
use App\Services\SlackService;
use App\Services\TessaAIService;
use App\Services\TessaContextBuilder;
use App\Services\TessaTaskService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TessaChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $includeArchived = $request->boolean('archived');
        $query = TessaChat::where('user_id', $user->id);
        if (! $includeArchived) {
            $query->where('is_archived', false);
        }
        $chats = $query->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'is_pinned', 'is_archived', 'created_at', 'updated_at']);

        return response()->json([
            'chats' => $chats->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title ?? 'New chat',
                'is_pinned' => (bool) $c->is_pinned,
                'is_archived' => (bool) $c->is_archived,
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(Request $request, TessaChat $chat): JsonResponse
    {
        $user = $request->user();
        if ($chat->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $allowed = ['title', 'is_pinned', 'is_archived'];
        $updates = [];
        foreach ($allowed as $key) {
            if ($request->has($key)) {
                if ($key === 'title') {
                    $updates[$key] = $request->input($key);
                } else {
                    $updates[$key] = $request->boolean($key);
                }
            }
        }
        if (! empty($updates)) {
            $chat->update($updates);
        }

        return response()->json([
            'ok' => true,
            'chat' => [
                'id' => $chat->id,
                'title' => $chat->title ?? 'New chat',
                'is_pinned' => (bool) $chat->is_pinned,
                'is_archived' => (bool) $chat->is_archived,
            ],
        ]);
    }

    public function messages(TessaChat $chat): JsonResponse
    {
        $user = request()->user();
        if ($chat->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $messages = $chat->messages()->orderBy('created_at')->get(['role', 'content', 'created_at']);

        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'text' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $messages = $request->input('messages', []);
        $chatId = $request->input('chat_id');

        if (! is_array($messages) || empty($messages)) {
            return response()->json(['error' => 'messages array is required'], 422);
        }

        $normalized = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? null;
            $content = $m['content'] ?? $m['text'] ?? '';
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $normalized[] = [
                'role' => $role,
                'content' => (string) $content,
            ];
        }

        if (empty($normalized)) {
            return response()->json(['error' => 'At least one valid message (user or assistant) is required'], 422);
        }

        $user = $request->user();

        if ($chatId) {
            $chat = TessaChat::where('id', $chatId)->where('user_id', $user->id)->first();
            if (! $chat) {
                return response()->json(['error' => 'Chat not found'], 404);
            }
        } else {
            $chat = TessaChat::create([
                'user_id' => $user->id,
                'title' => null,
            ]);
        }

        $lastUserContent = null;
        foreach (array_reverse($normalized) as $m) {
            if ($m['role'] === 'user') {
                $lastUserContent = $m['content'];
                break;
            }
        }

        TessaMessage::create([
            'tessa_chat_id' => $chat->id,
            'role' => 'user',
            'content' => $normalized[array_key_last($normalized)]['content'],
        ]);

        if ($lastUserContent && (empty($chat->title) || $chat->title === 'New chat')) {
            $title = strlen($lastUserContent) > 40
                ? substr($lastUserContent, 0, 37) . '...'
                : $lastUserContent;
            $chat->update(['title' => $title]);
        }

        try {
            $aiService = new TessaAIService;
            $intent = $aiService->extractIntent($lastUserContent ?? '', $normalized);

            $contextSuffix = '';

            // Handle confirm_sign_off: record sign-off if valid
            if ($intent['confirm_sign_off'] ?? false) {
                $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
                $status = SignoffStatusService::getStatus($user, $dateStr);
                if ($status['canSignOff'] ?? false) {
                    // Mirror SignoffController::store — back-fill sign-in if the
                    // user is somehow signed off without a sign-in row, so the
                    // dashboard's "signed in today" check stays consistent.
                    DailySignin::firstOrCreate(
                        ['user_id' => $user->id, 'signin_date' => $dateStr],
                        ['signed_in_at' => Carbon::parse($dateStr, 'Asia/Kolkata')->startOfDay()->setTimezone('UTC')],
                    );
                    $existing = DailySignoff::where('user_id', $user->id)
                        ->where('signoff_date', $dateStr)
                        ->first();
                    if (! $existing) {
                        DailySignoff::create([
                            'user_id' => $user->id,
                            'signoff_date' => $dateStr,
                            'signed_off_at' => now(),
                            'pending_snapshot' => $status['items'] ?? [],
                        ]);
                    }
                    $contextSuffix = "\n\nSIGN_OFF_RECORDED: The user has been successfully signed off. Reply with a short, warm thank-you and see-you-tomorrow message. No time or details needed.";
                } else {
                    $reason = ($status['signedOff'] ?? false) ? 'Already signed off' : 'pending items remain';
                    $contextSuffix = "\n\nSIGN_OFF_FAILED: {$reason}. Explain why they cannot sign off and what they need to complete.";
                }
            } else {
            $sd = $intent['send_dm'] ?? null;
            if (is_array($sd) && isset($sd['message']) && trim((string) $sd['message']) !== '') {
                $targetPerson = $sd['target_person'] ?? 'me';
                $targetUser = (strtolower(trim($targetPerson)) === 'me')
                    ? $user
                    : TessaContextBuilder::resolveTargetUser($user, $targetPerson);
                if ($targetUser) {
                    $slackService = new SlackService;
                    $slackUserId = $slackService->getUserIdByName($targetUser->name);
                    $sent = $slackUserId ? $slackService->sendDirectMessage($slackUserId, $sd['message']) : false;
                    if ($sent) {
                        $contextSuffix = "\n\nDM_SENT: You sent a Slack DM to {$targetUser->name} with the requested content. Confirm briefly (e.g. \"Done, sent to your Slack.\").";
                        Log::info('TessaChat: direct DM sent', ['target' => $targetUser->name]);
                    } else {
                        $contextSuffix = "\n\nDM_FAILED: The Slack message could not be sent. Apologize and suggest copying the content manually.";
                        Log::warning('TessaChat: direct DM failed', ['target' => $targetUser->name]);
                    }
                } else {
                    $contextSuffix = "\n\nDM_SKIPPED: Could not find user \"{$targetPerson}\". Apologize and ask the user to clarify.";
                }
            }
            }

            // Handle leave request via chat
            $lr = $intent['leave_request'] ?? null;
            if (is_array($lr) && !empty($lr['type'])) {
                $leaveService = app(LeaveService::class);
                $hasStartDate = !empty($lr['start_date']) && $lr['start_date'] !== '';

                if (!$hasStartDate) {
                    // Vague request like "I need leave" — ask for details, show existing leaves
                    $existing = \App\Models\LeaveRequest::where('user_id', $user->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->where('end_date', '>=', Carbon::now('Asia/Kolkata')->format('Y-m-d'))
                        ->with('leaveType')
                        ->orderBy('start_date')
                        ->get();

                    $existingText = '';
                    if ($existing->isNotEmpty()) {
                        $existingText = "\n\nExisting upcoming leaves:\n";
                        foreach ($existing as $ex) {
                            $existingText .= "- {$ex->leaveType->name}: {$ex->start_date->format('D, j M')} to {$ex->end_date->format('D, j M')} ({$ex->status})\n";
                        }
                    }

                    $leaveTypes = \App\Models\LeaveType::active()->forGender($user->gender)->get();
                    $autoApproved = $leaveTypes->where('requires_approval', false)->pluck('name')->map(fn($n) => str_replace(' Leave', '', $n))->join(', ');
                    $managerApproval = $leaveTypes->where('requires_approval', true)->pluck('name')->map(fn($n) => str_replace(' Leave', '', $n))->join(', ');

                    $contextSuffix .= "\n\nLEAVE_DETAILS_NEEDED: The user wants to apply leave but didn't provide enough details. DO NOT apply leave yet." . $existingText . "\n\nAvailable leave types — Auto-approved: {$autoApproved}. Manager approval needed: {$managerApproval}.\n\nAsk them: 1) What type of leave? 2) What dates? Keep it simple and conversational. Do NOT show any balance numbers or tables.";
                } else {
                    // Clear request with dates — check for duplicates first, then apply
                    try {
                        $startDate = $lr['start_date'];
                        $endDate = $lr['end_date'] ?: $startDate;

                        $overlap = \App\Models\LeaveRequest::where('user_id', $user->id)
                            ->whereIn('status', ['pending', 'approved'])
                            ->where('start_date', '<=', $endDate)
                            ->where('end_date', '>=', $startDate)
                            ->with('leaveType')
                            ->first();

                        if ($overlap) {
                            $contextSuffix .= "\n\nLEAVE_DUPLICATE: The user already has a {$overlap->leaveType->name} ({$overlap->status}) from {$overlap->start_date->format('D, j M')} to {$overlap->end_date->format('D, j M')} that overlaps with the requested dates. DO NOT create a new leave. Inform them about the existing leave and ask if they want different dates or want to cancel the existing one.";
                        } else {
                            $leaveRequest = $leaveService->applyLeave(
                                $user,
                                $lr['type'],
                                $startDate,
                                $endDate,
                                $lr['reason'] ?? null,
                                'chat'
                            );

                            if ($leaveRequest->status === 'approved') {
                                $contextSuffix .= "\n\nLEAVE_AUTO_APPROVED: {$leaveRequest->leaveType->name} has been auto-approved. Details: {$leaveRequest->start_date->format('D, j M')} to {$leaveRequest->end_date->format('D, j M')} ({$leaveRequest->total_days} day(s)). Manager has been notified on Slack.";
                            } else {
                                $contextSuffix .= "\n\nLEAVE_APPLIED: Leave request submitted and pending manager approval. Details: {$leaveRequest->leaveType->name} from {$leaveRequest->start_date->format('D, j M')} to {$leaveRequest->end_date->format('D, j M')} ({$leaveRequest->total_days} day(s)). Manager has been notified on Slack.";
                            }
                        }
                    } catch (\Throwable $e) {
                        $contextSuffix .= "\n\nLEAVE_FAILED: Could not apply leave. Error: {$e->getMessage()}. Apologize and suggest trying via the portal.";
                        Log::warning('TessaChat: leave request failed', ['error' => $e->getMessage()]);
                    }
                }
            }

            // Handle leave cancellation via chat
            $lc = $intent['leave_cancel'] ?? null;
            if (is_array($lc)) {
                try {
                    $leaveService = app(LeaveService::class);
                    $cancelDate = !empty($lc['date']) ? $lc['date'] : null;

                    // Find the leave request to cancel
                    $query = \App\Models\LeaveRequest::where('user_id', $user->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->with('leaveType')
                        ->orderBy('start_date');

                    if ($cancelDate) {
                        $query->where('start_date', '<=', $cancelDate)
                              ->where('end_date', '>=', $cancelDate);
                    } else {
                        // No date specified — find nearest upcoming leave
                        $query->where('end_date', '>=', Carbon::now('Asia/Kolkata')->format('Y-m-d'));
                    }

                    $leaveRequest = $query->first();

                    if ($leaveRequest) {
                        $wasApproved = $leaveRequest->isApproved();
                        $leaveService->cancelLeave($user, $leaveRequest);
                        $contextSuffix .= "\n\nLEAVE_CANCELLED: Successfully cancelled {$leaveRequest->leaveType->name} from {$leaveRequest->start_date->format('D, j M')} to {$leaveRequest->end_date->format('D, j M')}. Manager has been notified.";
                    } else {
                        // No matching leave found — show existing upcoming leaves
                        $upcoming = \App\Models\LeaveRequest::where('user_id', $user->id)
                            ->whereIn('status', ['pending', 'approved'])
                            ->where('end_date', '>=', Carbon::now('Asia/Kolkata')->format('Y-m-d'))
                            ->with('leaveType')
                            ->orderBy('start_date')
                            ->get();

                        if ($upcoming->isNotEmpty()) {
                            $list = '';
                            foreach ($upcoming as $ex) {
                                $list .= "- {$ex->leaveType->name}: {$ex->start_date->format('D, j M')} to {$ex->end_date->format('D, j M')} ({$ex->status})\n";
                            }
                            $contextSuffix .= "\n\nLEAVE_CANCEL_NOT_FOUND: No leave found for the specified date. Here are the user's upcoming leaves:\n{$list}\nAsk them which one they'd like to cancel.";
                        } else {
                            $contextSuffix .= "\n\nLEAVE_CANCEL_NOT_FOUND: The user has no upcoming leave requests to cancel.";
                        }
                    }
                } catch (\Throwable $e) {
                    $contextSuffix .= "\n\nLEAVE_CANCEL_FAILED: Could not cancel leave. Error: {$e->getMessage()}";
                    Log::warning('TessaChat: leave cancel failed', ['error' => $e->getMessage()]);
                }
            }

            // Handle leave balance check via chat
            if ($intent['leave_balance'] ?? false) {
                try {
                    $leaveTypes = \App\Models\LeaveType::active()->forGender($user->gender)->get();
                    $autoApproved = $leaveTypes->where('requires_approval', false)->pluck('name')->map(fn($n) => str_replace(' Leave', '', $n))->join(', ');
                    $managerApproval = $leaveTypes->where('requires_approval', true)->pluck('name')->map(fn($n) => str_replace(' Leave', '', $n))->join(', ');

                    // Get recent leave history
                    $recentLeaves = \App\Models\LeaveRequest::where('user_id', $user->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->where('end_date', '>=', Carbon::now('Asia/Kolkata')->format('Y-m-d'))
                        ->with('leaveType')
                        ->orderBy('start_date')
                        ->get();

                    $leavesText = '';
                    if ($recentLeaves->isNotEmpty()) {
                        $leavesText = "\n\nUpcoming/active leaves:\n";
                        foreach ($recentLeaves as $lv) {
                            $leavesText .= "- {$lv->leaveType->name}: {$lv->start_date->format('D, j M')} to {$lv->end_date->format('D, j M')} ({$lv->status})\n";
                        }
                    } else {
                        $leavesText = "\n\nNo upcoming leaves.";
                    }

                    $contextSuffix .= "\n\nLEAVE_INFO: Available leave types — Auto-approved: {$autoApproved}. Manager approval needed: {$managerApproval}. No pay cuts, no restrictions. {$leavesText}\n\nTell the user their available leave types and any upcoming leaves. Do NOT show balance numbers, totals, or tables. Keep it simple.";
                } catch (\Throwable $e) {
                    $contextSuffix .= "\n\nLEAVE_BALANCE_FAILED: Could not fetch leave info. Apologize and suggest trying via the portal.";
                }
            }

            // Handle task creation intent — inject team members list
            if ($intent['create_task'] ?? false) {
                $teamMembers = User::select('id', 'name')->orderBy('name')->get();
                $memberList = $teamMembers->map(fn ($u) => "{$u->name} (ID: {$u->id})")->join(', ');
                $contextSuffix .= "\n\nTASK_CREATION_MODE: The user wants to create a task. Team members: {$memberList}. Guide them step by step (one question at a time). When matching a name, use the closest match from this list.";
            }

            // Skip heavy context building for pure leave intents
            $isLeaveOnly = (
                (is_array($intent['leave_request'] ?? null) || ($intent['leave_balance'] ?? false) || is_array($intent['leave_cancel'] ?? null) || ($intent['create_task'] ?? false))
                && !($intent['sign_off'] ?? false)
                && !($intent['sign_in'] ?? false)
                && !($intent['pending_work'] ?? false)
            );

            // Skip heavy full-context build for sign-in/sign-off/pending — they append their own specific context later
            $isSpecificIntent = ($intent['sign_in'] ?? false) || ($intent['sign_off'] ?? false) || ($intent['pending_work'] ?? false);

            if ($isLeaveOnly || $isSpecificIntent) {
                $today = Carbon::now('Asia/Kolkata');
                $context = "Today: {$today->format('Y-m-d')} ({$today->format('l')}), current time: {$today->format('g:i A')} IST";
                $contextBuilder = new TessaContextBuilder;
            } else {
                $contextBuilder = new TessaContextBuilder;
                $context = ($intent['is_general'] ?? true)
                    ? $contextBuilder->build($user)
                    : $contextBuilder->buildForIntent($user, $intent);
            }

            // Inject sign-off checklist when user requests sign-off (and is not confirming)
            if (($intent['sign_off'] ?? false) && ! ($intent['confirm_sign_off'] ?? false)) {
                $today = $today ?? Carbon::now('Asia/Kolkata');
                $context .= "\n\n" . $contextBuilder->buildSignoffContext($user);
                $context .= "\n\nIMPORTANT: Today is " . $today->format('l, F j, Y') . '. Use EXACTLY this date in your response.';
                $context .= "\n\nPresent the sign-off checklist clearly. If all items are complete, ask the user to confirm the sign-off (e.g. \"Say 'yes, sign me off' to confirm\"). If items are pending, list what needs to be done.";
            }

            // Inject sign-in / morning briefing when user requests sign-in
            if ($intent['sign_in'] ?? false) {
                $today = Carbon::now('Asia/Kolkata');
                DailySignin::ensureForKolkataDate($user, $today->format('Y-m-d'));
                $context .= "\n\n" . $contextBuilder->buildSigninContext($user);
                $context .= "\n\nIMPORTANT: Today is " . $today->format('l, F j, Y') . '. Use EXACTLY this date in your response — do NOT use any other date.';
                $context .= "\n\nPresent a morning briefing. Greet warmly, then use a markdown table with columns: Section | Details | Status. Include meetings (with time), daily report status, and KPIs as rows. Mark items as Pending or Complete in the Status column.";
            }

            // Inject pending work context when user requests pending work
            if ($intent['pending_work'] ?? false) {
                $context .= "\n\n" . $contextBuilder->buildPendingWorkContext($user);
                $context .= "\n\nPresent a clear summary of all pending work items. Use a markdown table with columns: Item | Details | Status. Group by type (Action Items, Meeting Notes, Daily Report, KPIs). Only show items that need attention (pending/overdue/missing). Do NOT suggest agenda or notes prep for meetings where the user has Role: attendee — only for Role: owner. If everything is complete, congratulate the user.";
            }

            $context .= $contextSuffix;

            Log::debug('TessaChat: context built', [
                'user_id' => $user->id,
                'intent' => $intent,
                'context_length' => strlen($context),
            ]);
        } catch (\Throwable $e) {
            Log::error('TessaChat: context build failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $context = '';
        }

        try {
            $service = new TessaAIService;
            $userRoleTitle = $user->roleRelation?->name ?? ucfirst(str_replace('_', ' ', $user->role ?? 'user'));
            $isDateSensitive = ($intent['sign_in'] ?? false) || ($intent['sign_off'] ?? false) || ($intent['confirm_sign_off'] ?? false) || ($intent['pending_work'] ?? false) || $isLeaveOnly;
            $aiMessages = $isDateSensitive
                ? [['role' => 'user', 'content' => $lastUserContent ?? end($normalized)['content'] ?? '']]
                : array_slice($normalized, -10);
            $reply = $service->chat($aiMessages, $context, $user->name, $userRoleTitle);
            Log::debug('TessaChat: AI reply received', [
                'chat_id' => $chat->id,
                'reply_length' => strlen($reply),
            ]);
        } catch (\Throwable $e) {
            Log::error('TessaChat: AI service failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $reply = 'Sorry, I encountered an error. Please try again.';
        }

        // Auto-create task if AI response contains TASK_CREATE JSON
        $taskCreated = null;
        if (preg_match('/TASK_CREATE:\s*(\{.+?\})/s', $reply, $matches)) {
            try {
                $taskData = json_decode($matches[1], true);
                if (is_array($taskData) && ! empty($taskData['title'])) {
                    // Resolve assigned_to name to user ID
                    $assigneeName = $taskData['assigned_to'] ?? '';
                    $assignee = User::where('name', 'like', "%{$assigneeName}%")->first();

                    if ($assignee) {
                        $service = app(TessaTaskService::class);
                        $task = $service->createAndNotify(
                            $user,
                            $assignee->id,
                            $taskData['title'],
                            $taskData['description'] ?? null,
                            $taskData['priority'] ?? 'medium',
                            $taskData['deadline'] ?? null
                        );

                        // Auto-create thread participants
                        \App\Models\TaskParticipant::firstOrCreate(
                            ['task_id' => $task->id, 'user_id' => $user->id],
                            ['role' => 'assigner']
                        );
                        if ($task->assigned_to !== $user->id) {
                            \App\Models\TaskParticipant::firstOrCreate(
                                ['task_id' => $task->id, 'user_id' => $task->assigned_to],
                                ['role' => 'assignee']
                            );
                        }

                        $taskCreated = $task->id;

                        // Clean the TASK_CREATE block from the reply
                        $reply = preg_replace('/```?TASK_CREATE:\s*\{.+?\}```?/s', '', $reply);
                        $reply = trim($reply) . "\n\nTask created successfully and {$assignee->name} has been notified on Slack.";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('TessaChat: auto task creation failed', ['error' => $e->getMessage()]);
            }
        }

        TessaMessage::create([
            'tessa_chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        $response = ['ok' => true, 'reply' => $reply, 'chat_id' => $chat->id];
        if ($taskCreated) {
            $response['task_created'] = $taskCreated;
        }

        return response()->json($response);
    }

    public function destroy(TessaChat $chat): JsonResponse
    {
        $user = request()->user();
        if ($chat->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $chat->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Grammar/spelling correction for any text input. Used by the inline
     * "fix grammar" icon attached to textareas across the app.
     */
    public function grammar(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
        ]);

        $original = trim($validated['text']);
        if ($original === '') {
            return response()->json(['ok' => true, 'text' => '', 'changed' => false]);
        }

        $corrected = (new TessaAIService)->correctGrammar($original);

        if ($corrected === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Grammar service is temporarily unavailable. Please try again in a moment.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'text' => $corrected,
            'changed' => $corrected !== $original,
        ]);
    }

    public function dreamStory(Request $request): JsonResponse
    {
        $name = $request->user()->name ?? 'friend';

        $themes = [
            'adventure — you and them exploring somewhere wild (underwater kingdom, space, jungle, volcano, treasure hunt)',
            'food — cooking together, discovering magical food, a feast, a food fight, opening a restaurant on the moon',
            'getting rich — they become a billionaire, buy you luxury cat things, gold scratching posts, diamond collars',
            'travel — road trip in a flying car, sailing on a tuna boat, riding dolphins, train through candy land',
            'superpower — they get superpowers, you both save the city, flying together, invisibility pranks',
            'celebrity life — you both become famous, red carpet, paparazzi chasing you, winning awards together',
            'silly mishap — everything goes hilariously wrong, they accidentally shrink you, you swap bodies',
            'sports — winning a championship together, you coaching them, epic cricket/football/swimming moment',
            'animals — you recruit an army of cats, they become king/queen of animals, riding elephants',
            'time travel — visiting dinosaurs, going to the future, meeting their baby self',
            'music — forming a band, you playing drums with paws, viral concert, singing on rooftops',
            'magic — they become a wizard, you are the familiar, casting silly spells, enchanted office',
        ];
        $theme = $themes[array_rand($themes)];

        $systemPrompt = 'You are a sleepy cat named Tessa. You are napping and having a vivid, hilarious dream about the person whose name is given. '
            . 'Theme for this dream: ' . $theme . '. '
            . 'Write 2-3 sentences of a fun, creative, unexpected dream story. Be WILDLY imaginative — absurd details, plot twists, dramatic moments. '
            . 'Start with "Zzz..." and end with "...Zzz 😴". Mention the person by their first name. '
            . 'Write from the cat\'s perspective (first person "I"). Mix in cat behavior (purring, knocking things off tables, demanding fish). '
            . 'Make it so funny and vivid that the person reading it can\'t help but smile. '
            . 'Keep it under 250 characters. Plain text only, no markdown. Emoji welcome.';

        $service = new TessaAIService;
        $story = $service->mediumAi($systemPrompt, "The person's name is: {$name}. Remember: be creative, unique, and hilarious. No generic dreams!");

        if (empty($story)) {
            $firstName = explode(' ', $name)[0];
            $story = "Zzz... I'm dreaming that {$firstName} bought me the biggest fish in the ocean... then we napped on a cloud together... Zzz 😴";
        }

        return response()->json(['story' => $story]);
    }

    public function morningQuote(Request $request): JsonResponse
    {
        $user = $request->user();
        $firstName = explode(' ', $user->name ?? 'friend')[0];
        $today = Carbon::now('Asia/Kolkata')->toDateString();
        $cacheKey = "morning_quote:{$user->id}:{$today}";

        $quote = Cache::remember($cacheKey, now()->addHours(36), function () use ($firstName) {
            $angles = [
                'a sharp insight on showing up consistently even when motivation is gone',
                'a reframe on the gap between today\'s small effort and the compounding result a year from now',
                'a take on courage when work feels mundane or invisible',
                'the underrated power of finishing the last 10% well',
                'how craft and care separate good work from forgettable work',
                'why patience and discipline beat bursts of intensity',
                'the difference between being busy and being effective today',
                'a perspective on owning the outcome instead of the task',
                'why small honest improvements today are worth more than grand plans tomorrow',
                'how to start a hard day when you don\'t feel ready',
                'why high standards are kinder than low ones',
                'the quiet pride of doing your own work well',
            ];
            $angle = $angles[array_rand($angles)];

            $systemPrompt = 'You are writing a single short motivational line for an employee who has just signed in to start their workday. '
                . 'Angle for today: ' . $angle . '. '
                . 'Constraints: ONE sentence, 12-22 words. Address them directly using their first name (which the user will provide). '
                . 'No clichés ("rise and grind", "crush it", "today is the day"). No emoji. No quotation marks around the line. Plain text. '
                . 'Sound like a thoughtful senior colleague, not a self-help poster.';

            $service = new TessaAIService;
            $line = $service->mediumAi($systemPrompt, "First name: {$firstName}");
            $line = trim($line, " \t\n\r\0\x0B\"'");

            if ($line === '') {
                $fallbacks = [
                    "{$firstName}, the version of today you'll be proud of is built from small honest choices, starting now.",
                    "{$firstName}, do the next right thing well — momentum will follow before motivation does.",
                    "{$firstName}, the work you do quietly today is what your future self will thank you for.",
                    "{$firstName}, high standards aren't pressure — they're a gift you're giving the people who depend on you.",
                ];
                $line = $fallbacks[array_rand($fallbacks)];
            }

            return $line;
        });

        return response()->json([
            'quote' => $quote,
            'date'  => $today,
        ]);
    }
}
