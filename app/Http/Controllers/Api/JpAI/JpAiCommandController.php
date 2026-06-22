<?php

namespace App\Http\Controllers\Api\JpAI;

use App\Http\Controllers\Controller;
use App\Models\TessaChat;
use App\Models\TessaMessage;
use App\Models\User;
use App\Services\TessaAIService;
use App\Services\TessaContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * JP AI Command Center.
 *
 * JP (user_id=1) has 44 sidebar sections. This endpoint powers a single chat
 * that replaces the sidebar: JP types natural language, the AI replies and
 * emits a structured [ACTION] block telling the frontend to open a section or
 * pre-fill the task modal. Co-pilot model — the AI never saves anything; it
 * navigates and pre-fills, JP confirms.
 *
 * Reuses the existing Tessa chat stack (TessaChat/TessaMessage tables,
 * TessaAIService, TessaContextBuilder). Gated to JP only + the JP_AI_MODE flag.
 */
class JpAiCommandController extends Controller
{
    /** Sections JP can navigate to (key => human description for the prompt). */
    private const SECTIONS = [
        'dashboard' => 'Daily sign-in widget, team pending items, leave overview, mission metrics',
        'mission' => 'Company mission, values, OKRs, mission-critical metrics',
        'tasks' => 'Assign and track tasks across the team',
        'checklists' => 'Daily task checklists per employee',
        'meetings' => 'Weekly meeting board with agendas and MOMs',
        'kpi_report' => 'Individual and team KPI scorecards',
        'weeklyTimesheet' => 'Team weekly work-hour records',
        'mkpi' => 'Marketing KPI metrics (Hima/OnlyCare/Sudar)',
        'org' => 'Company org chart',
        'bills' => 'Employee bills and reimbursement submissions',
        'invoices' => 'Supplier invoices and bank reconciliation',
        'revenue' => 'Company revenue tracking and payouts',
        'meta_ads' => 'Facebook/Instagram ad spend and performance',
        'google_ads' => 'Google Ads performance data',
        'employees' => 'Employee records, profiles, salary, HR data (the Team page)',
        'hr_dashboard' => 'HR dashboard with attendance and leave summary',
        'letters' => 'Offer and appointment letter generator',
        'team_status' => 'Real-time employee status grid',
        'agile' => 'Sprint board, stories, epics, bugs',
        'workforceAdmin' => 'Weekly workforce summary and OT tracker',
        'manager_ratings' => 'Friday manager ratings for all subordinates',
        'network_leverage' => 'Relationship and network leverage data',
        'ai_expense' => 'AI/LLM credit usage and spend tracking',
        'notes' => 'Personal notes / founder journal',
        'logs' => 'Founder activity log entries',
        'claude_context' => 'Daily AI summaries from the team (Claude MCP context)',
        'profile' => 'Personal profile, photo, joining date, date of birth',
        'leave' => 'Your own leave AND a "Team Leave" tab showing the leave of people who report to you (your team / your direct reports / people under you)',
        'team_leave' => 'Company-wide monthly leave board covering EVERY employee in the company (all staff, not just your team)',
        'holidays' => 'Holiday calendar and upcoming birthdays',
        'my_score' => 'KRA scorecards for all employees (Team KRAs)',
        'schedule' => 'Personal and team schedule',
        'policies' => 'Company policy handbook',
        'archives' => 'Slack insights, GitHub, Google Drive consolidated',
        'rewards' => 'Reward task management — assign, approve, pay',
        'timesheetTracker' => 'OT / extra-hours tracker',
        'signin_status' => 'Colour-coded grid of who has signed in today (live)',
        'hima_revenue_sheet' => 'Embedded Google Sheet — Hima revenue data',
        'onlycare_revenue_sheet' => 'Embedded Google Sheet — Only Care revenue data',
        'sudar_revenue_sheet' => 'Embedded Google Sheet — Sudar revenue data',
        'cpa_master_sheet' => 'Embedded Google Sheet — CPA master data',
        'hr_records' => 'Employee records — ESIC sheet + Drive folder browser',
        'hiring' => 'ATS, job descriptions, candidate pipeline',
        'tickets' => 'Support / technical tickets',
    ];

    private const HISTORY_LIMIT = 12;

    public function handle(Request $request, TessaAIService $ai, TessaContextBuilder $contextBuilder): JsonResponse
    {
        $user = $request->user();

        // Gate: feature flag + JP only.
        if (! config('jp_ai.enabled') || (int) $user->id !== (int) config('jp_ai.user_id', 1)) {
            abort(403, 'JP AI command center is not available for this account.');
        }

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'chat_id' => 'nullable|integer',
        ]);

        $message = trim($validated['message']);
        if ($message === '') {
            return response()->json(['error' => 'Message is required.'], 422);
        }

        // Resolve / create the chat (own chats only).
        $chat = null;
        if (! empty($validated['chat_id'])) {
            $chat = TessaChat::where('id', $validated['chat_id'])
                ->where('user_id', $user->id)
                ->first();
        }
        if (! $chat) {
            $chat = TessaChat::create(['user_id' => $user->id, 'title' => null]);
        }

        // Load recent history (oldest → newest) for multi-turn context.
        $history = $chat->messages()
            ->orderByDesc('created_at')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        // Persist the user message + set a title from the first message.
        TessaMessage::create([
            'tessa_chat_id' => $chat->id,
            'role' => 'user',
            'content' => $message,
        ]);
        if (empty($chat->title) || $chat->title === 'New chat') {
            $chat->update([
                'title' => strlen($message) > 40 ? substr($message, 0, 37) . '...' : $message,
            ]);
        }

        // Build the prompt + call the AI.
        $liveData = '';
        try {
            $liveData = $contextBuilder->build($user);
        } catch (\Throwable $e) {
            Log::warning('JpAiCommand: context build failed', ['err' => $e->getMessage()]);
        }

        $systemPrompt = $this->buildSystemPrompt($user, $liveData);
        $aiMessages = array_merge($history, [['role' => 'user', 'content' => $message]]);

        $raw = '';
        try {
            $raw = $ai->customChat($systemPrompt, $aiMessages);
        } catch (\Throwable $e) {
            Log::error('JpAiCommand: AI call failed', ['err' => $e->getMessage()]);
        }
        if ($raw === '') {
            $raw = "Sorry, I couldn't process that just now. Please try again.\n[ACTION]{\"type\":\"none\",\"params\":{}}[/ACTION]";
        }

        [$reply, $action] = $this->splitReplyAndAction($raw);

        TessaMessage::create([
            'tessa_chat_id' => $chat->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        return response()->json([
            'ok' => true,
            'reply' => $reply,
            'action' => $action,
            'chat_id' => $chat->id,
        ]);
    }

    /**
     * Pull the [ACTION]{...}[/ACTION] block out of the raw AI text. Returns
     * [cleanReply, actionArrayOrNull]. A malformed or missing block degrades to
     * a null action (pure conversation) rather than erroring.
     */
    private function splitReplyAndAction(string $raw): array
    {
        $action = null;
        if (preg_match('/\[ACTION\](.*?)\[\/ACTION\]/s', $raw, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded) && ! empty($decoded['type']) && $decoded['type'] !== 'none') {
                $action = [
                    'type' => (string) $decoded['type'],
                    'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
                ];
            }
        }

        // Strip every action block from the visible reply, then tidy whitespace.
        $reply = preg_replace('/\[ACTION\].*?\[\/ACTION\]/s', '', $raw);
        $reply = trim(preg_replace("/\n{3,}/", "\n\n", $reply));
        if ($reply === '') {
            $reply = 'Done.';
        }

        return [$reply, $action];
    }

    private function buildSystemPrompt(User $user, string $liveData): string
    {
        $firstName = explode(' ', trim($user->name ?? 'JP'))[0] ?: 'JP';

        $sectionLines = [];
        foreach (self::SECTIONS as $key => $desc) {
            $sectionLines[] = "- {$key} — {$desc}";
        }
        $sectionCatalog = implode("\n", $sectionLines);

        // People list so the AI can resolve names → assignee_id for task creation.
        $people = User::query()
            ->whereNotNull('name')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
        $peopleJson = json_encode($people, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are Tessa, {$firstName}'s personal AI command center inside the InnovFix portal. {$firstName} is the CEO and founder. He has dozens of portal sections and dislikes hunting through a long sidebar — your job is to take a natural-language request, reply in 1–3 short conversational sentences, and then ALWAYS emit exactly ONE action block at the very END of your reply.

The action block tells the portal what to open for him. Format (must be valid JSON on a single line, wrapped in the literal tags):
[ACTION]{"type":"<type>","params":{...}}[/ACTION]

ACTION TYPES
1) Navigate to a section (read-only — he can always close back to you):
   [ACTION]{"type":"open_section","params":{"section":"<exact_key>"}}[/ACTION]
   For the "leave" section you may add "tab": use "team" when he wants the leave of
   his team / people who report to him, or "mine" for his own leave. Example:
   [ACTION]{"type":"open_section","params":{"section":"leave","tab":"team"}}[/ACTION]
2) Open the NEW task form, pre-filled (he fills the rest and clicks Assign):
   [ACTION]{"type":"open_task_new","params":{"assignee_id":<int or null>,"title":<string or null>}}[/ACTION]
3) Open an EXISTING task to edit (only when he names a specific task id):
   [ACTION]{"type":"open_task_edit","params":{"task_id":<int>}}[/ACTION]
4) Pure conversation — no navigation needed:
   [ACTION]{"type":"none","params":{}}[/ACTION]

SECTION KEYS (use the EXACT key on the left):
{$sectionCatalog}

RULES
- ALWAYS end with exactly one action block. For a plain answer/question, use type "none".
- For section navigation: open it immediately and say what you're opening (e.g. "Opening Sign-In Status."). No confirmation step needed — he can close back to you anytime.
- For assigning a task: briefly confirm who it's for, then emit open_task_new. Resolve the person's name to their id from the PEOPLE list below. If you can't confidently match a name, set assignee_id to null and ask him to pick in the form. Pre-fill "title" only if he clearly stated the task; otherwise null.
- If a request maps to data you can see in LIVE DATA, answer it directly in your reply (and use action "none" unless he also wants a section opened).
- If a request doesn't match any section, ask a short clarifying question with action "none". Never invent a section key.
- Keep replies tight and warm. No long preambles, no markdown headers, no walls of text.

PEOPLE (for task assignment — match names to these ids):
{$peopleJson}

--- LIVE DATA ---
{$liveData}
PROMPT;

        return $prompt;
    }
}
