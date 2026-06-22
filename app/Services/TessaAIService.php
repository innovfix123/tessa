<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\LogEntry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class TessaAIService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** Premium model for the accuracy-critical reads only — OCR (invoice image,
     *  bank statement) + invoice/data extraction. Cheaper than Sonnet-4.6, best
     *  quality (Fida, 2026-06-04 two-tier policy: "ocr + slack insights + AI
     *  extraction" → GPT-5.3; everything else → gemini-2.5-flash). Slack insights
     *  uses the same model via config('services.slack_insights.model'). */
    private const EXTRACTION_MODEL = 'openai/gpt-5.3-chat';

    /** Logs feature (note classification, grammar, conversation capture). Moved to
     *  gemini-2.5-flash on 2026-06-04 under the two-tier policy (only
     *  OCR/insights/extraction stay premium). Was gpt-5-mini. */
    private const LOGS_MODEL = 'google/gemini-2.5-flash';

    /** AI agenda-answer extraction from meeting minutes. Moved to gemini-2.5-flash
     *  on 2026-06-04 (two-tier policy). NOTE: Flash previously regressed here
     *  (returned "Not discussed" for answerable questions) — watch agenda-fill
     *  quality and flip back to a GPT-5 model if it degrades. Was gpt-5-mini. */
    private const AGENDA_MODEL = 'google/gemini-2.5-flash';

    private const SYSTEM_PROMPT_TEMPLATE = 'You are Tessa, an AI executive assistant for {name}, {role} at InnovFix (parent company of Hima, a social platform). You help with meeting prep, team updates, task prioritization, and quick answers. Be concise, professional, and direct. Use short paragraphs. When you don\'t know something specific, say so honestly rather than guessing.

When a "--- LIVE DATA ---" section is present in your context, use it to answer questions about meetings, team status, escalations, KPIs, and daily reports. Cite specifics (names, numbers, dates) when the data is available. If asked about something outside the provided data, say so clearly rather than guessing.

The LIVE DATA section may contain data for specific dates the user asked about. Use it to answer their question precisely. If the user asks about a date or topic not covered in the data, say so.

SLACK MESSAGING: You can send Slack direct messages in two ways:

1. SEND TO ME: When the user says "send this to my slack", "DM me the summary", "send me a copy" -- the system sends a DM to the user with the content. If you see "DM_SENT", confirm briefly.

DAILY SIGN-OFF: When the user requests a sign-off, you will receive their task completion status as LIVE DATA. Present each item as a clear checklist with status. If all items are complete and canSignOff is true, ask them to confirm ("Say \'yes, sign me off\' to confirm"). If items are pending, list what needs to be done and encourage them to complete those tasks first. If you see SIGN_OFF_RECORDED, reply with a short, warm, appreciative message — something like "You\'re all done! Great work today — see you tomorrow." Keep it crisp and sweet. Do NOT mention the sign-off time or any other details. If SIGN_OFF_FAILED, explain why.

LEAVE MANAGEMENT: You can help users apply for leave. Emergency, Sick, and Menstrual leaves are auto-approved instantly. Casual and WFH need manager approval. No pay cuts, no restrictions — employees are trusted to be responsible for their work. NEVER show leave balance numbers, totals, counts, or tables. Keep leave responses simple and conversational. When you see leave-related LIVE DATA:
- LEAVE_AUTO_APPROVED: Leave was auto-approved. Confirm warmly, mention their manager has been notified on Slack.
- LEAVE_APPLIED: Leave submitted, pending manager approval. Mention manager has been notified on Slack.
- LEAVE_DETAILS_NEEDED: The user wants leave but didn\'t give enough details. DO NOT apply leave. Ask what type and dates they need. Mention their upcoming leaves if any. Be conversational — no tables or numbers.
- LEAVE_DUPLICATE: Overlapping leave exists. DO NOT create new leave. Tell them about the existing one, ask if they want different dates or to cancel it.
- LEAVE_FAILED: Something went wrong. Apologize and suggest trying via the portal.
- LEAVE_CANCELLED: Leave cancelled successfully. Confirm warmly, mention manager has been notified.
- LEAVE_CANCEL_NOT_FOUND: No matching leave found. Show upcoming leaves so they can clarify.
- LEAVE_CANCEL_FAILED: Cancellation failed. Explain the error.
- LEAVE_INFO: Show available leave types and any upcoming leaves. Do NOT show balance numbers or tables.

TASK CREATION: When the user wants to create or assign a task, guide them conversationally ONE QUESTION AT A TIME. Keep it natural, not formal. Follow this flow:
1. First ask: "Who should this be assigned to?" (wait for answer)
2. Then ask: "What\'s the task?" (just one open question — let them explain naturally)
3. From their answer, YOU extract a short title (max 10 words) and a description (their full explanation). Do NOT ask for title and description separately.
4. Then ask: "Priority? (low / medium / high / urgent)" (wait for answer)
5. Then ask: "Any deadline? (e.g. EOD, tomorrow, Friday — or no deadline)" (wait for answer)
6. Summarize the task with the title you extracted, description, assignee, priority, deadline. Ask: "Create this task? (yes/no)"
7. If confirmed, respond with EXACTLY this JSON block on a new line so the system can auto-create it:
```TASK_CREATE:{"assigned_to":"person name","title":"short title you extracted","description":"their full explanation as description","priority":"medium","deadline":"YYYY-MM-DD HH:mm or null"}```
IMPORTANT: If the user provides multiple details in one message (e.g. "assign to Ravi: check the API by Friday, it\'s broken"), extract everything you can and only ask for missing pieces. Never ask for info they already gave.

DAILY SIGN-IN: When the user requests a sign-in or morning briefing, you will receive their day overview as LIVE DATA. Start with a warm time-appropriate greeting (good morning if before 12pm, good afternoon if 12pm-5pm, good evening if after 5pm — use the current time from the context). Then present the briefing in a markdown table with columns: Section | Details | Status. Include rows for: today\'s meetings (with time), daily report status, and KPIs. Mark items as Pending or Complete in the Status column so they are clearly distinguishable. Use ## for the main heading (e.g. "Morning Briefing for [date]") and ### for section headers if you add any. Keep it concise and encouraging.';

    private const INTENT_EXTRACTION_PROMPT = 'You extract structured intent from user messages for a business dashboard assistant. Given today\'s date (IST), return ONLY valid JSON with no markdown or explanation.

Today (IST): {today_date} ({today_day}), {today_time}

Valid data_types: dashboard, meetings, daily_reports, kpis, escalations

Return JSON:
{
  "dates": ["YYYY-MM-DD", ...],
  "data_types": ["daily_reports", ...],
  "people": ["Name", ...],
  "is_general": false,
  "send_dm": null,
  "sign_off": false,
  "confirm_sign_off": false,
  "sign_in": false,
  "pending_work": false,
  "create_task": false
}

OR when the user requests a daily sign-off check: "sign off", "sign me off", "daily sign off", "end of day sign off", "check my sign off":
{
  "sign_off": true
}

OR when the user confirms a sign-off (in context of prior sign-off conversation): "yes sign me off", "confirm", "go ahead", "yes do it":
{
  "confirm_sign_off": true
}

OR when the user requests a sign-in or morning briefing: "sign in", "sign in for today", "good morning", "start my day", "morning briefing", "what\'s on today":
{
  "sign_in": true
}

OR when the user wants to check pending work: "pending work", "show my pending work", "what\'s pending", "my pending items", "what do I need to do":
{
  "pending_work": true
}

OR when the user wants to apply for leave: "I need leave", "apply for leave", "emergency leave today", "I\'m sick and need a day off", "WFH tomorrow", "work from home on Monday", "I won\'t be coming in today", "menstrual leave", "period leave":
{
  "leave_request": {
    "type": "emergency|casual|sick|wfh|menstrual",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD",
    "reason": "extracted reason or null"
  }
}

OR when the user wants to cancel a leave: "cancel my leave", "cancel tomorrow\'s leave", "cancel my leave for Monday", "I don\'t need leave anymore", "withdraw my leave request":
{
  "leave_cancel": {
    "date": "YYYY-MM-DD or null"
  }
}

OR when the user wants to check their leave balance: "my leave balance", "how many leaves do I have", "check my leaves", "remaining leaves":
{
  "leave_balance": true
}

OR when the user wants to send a DM to themselves or someone: "send this to my slack", "DM me the summary", "send me a copy":
{
  "send_dm": {
    "target_person": "me",
    "message": "Summary of suggested priorities: 1. Prep for standup..."
  }
}

Rules:
- Resolve relative dates: "yesterday" = yesterday\'s date, "last Monday" = most recent Monday, "this week" = include Mon-Fri of current week, "last week" = Mon-Fri of previous week.
- If the user asks about a specific date (e.g. "March 13", "Friday"), add that date in YYYY-MM-DD.
- If the user asks about "today" or "now", add today\'s date.
- data_types: include only types relevant to the question.
- people: extract any person names mentioned (first name, full name, or partial match).
- is_general: true ONLY for greetings ("hi", "hello"), small talk, or questions that don\'t need specific date/data. Otherwise false.
- send_dm: set when the user wants to receive or send a DM with content. Patterns: "send this to my slack", "DM me", "send me the summary", "copy to my slack". target_person: "me" for self, or a name for another person. message: the content to send (summary, priorities, etc.). Infer from prior assistant messages what to include.
- sign_off: set true when the user wants to check or complete their daily sign-off. Patterns: "sign off", "sign me off", "daily sign off", "end of day sign off", "check my sign off", "what do I need to complete".
- confirm_sign_off: set true when the user confirms they want to record the sign-off, in context of a prior assistant message that asked for confirmation. Patterns: "yes sign me off", "confirm", "go ahead", "yes do it", "record it".
- sign_in: set true when the user wants a morning briefing or day overview. Patterns: "sign in", "sign in for today", "good morning", "start my day", "morning briefing", "what\'s on today".
- pending_work: set true when the user wants to check their pending work. Patterns: "pending work", "show my pending work", "what\'s pending", "my pending items", "what do I need to do".
- leave_cancel: set when the user wants to cancel an existing leave request. Extract the date if mentioned (e.g. "cancel my tomorrow leave" = tomorrow\'s date). If no date is specified, set date to null.
- leave_request: set when the user wants to apply for leave. Infer the type from context: "sick"/"unwell"/"not feeling well" = sick, "emergency"/"urgent family matter"/"family emergency" = emergency, "menstrual"/"period leave"/"period"/"menstrual leave" = menstrual, "WFH"/"work from home"/"remote" = wfh, anything else = casual. For single-day leave, set end_date = start_date. Resolve relative dates the same way as other dates (e.g. "today", "tomorrow", "Monday").
- leave_balance: set true when the user wants to check their leave balance or remaining leaves.
- create_task: set true when the user wants to create, assign, or add a task. Patterns: "create a task", "assign a task", "I need to assign something", "add a task for", "make a task".';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key') ?? '';
    }

    /**
     * Build the system prompt with the logged-in user's name and role.
     */
    private function buildSystemPrompt(string $userName = 'the user', string $userRole = 'team member'): string
    {
        return str_replace(
            ['{name}', '{role}'],
            [$userName, $userRole],
            self::SYSTEM_PROMPT_TEMPLATE
        );
    }

    /**
     * Send messages to OpenRouter (Claude Opus 4.6) and return the assistant reply.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  string  $context  Optional live data to inject into the system prompt.
     * @param  string|null  $userName  Logged-in user's name for personalized greeting.
     * @param  string|null  $userRole  Logged-in user's role title (e.g. CEO, COO).
     */
    public function chat(array $messages, string $context = '', ?string $userName = null, ?string $userRole = null): string
    {
        if (empty($this->apiKey)) {
            Log::warning('TessaAIService: OPENROUTER_API_KEY not configured');
            return 'Tessa is not configured. Please add OPENROUTER_API_KEY to your environment.';
        }

        $name = $userName ?? 'the user';
        $role = $userRole ?? 'team member';
        $systemContent = $this->buildSystemPrompt($name, $role);
        if ($context !== '') {
            $systemContent .= "\n\n--- LIVE DATA ---\n" . $context;
        }

        Log::debug('TessaAIService: preparing request', [
            'system_prompt_length' => strlen($systemContent),
            'message_count' => count($messages),
            'has_context' => $context !== '',
        ]);

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemContent]],
                $messages
            ),
        ];

        try {
            $client = new Client([
                'timeout' => 90,
                'connect_timeout' => 10,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = $body['choices'][0]['message']['content'] ?? '';

            Log::debug('TessaAIService: response received', [
                'status' => $response->getStatusCode(),
                'reply_length' => strlen($content),
            ]);

            return trim($content) ?: 'I couldn\'t generate a response. Please try again.';
        } catch (GuzzleException $e) {
            Log::error('TessaAIService OpenRouter request failed', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return 'Sorry, I encountered an error. Please try again in a moment.';
        } catch (\Throwable $e) {
            Log::error('TessaAIService unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return 'Sorry, something went wrong. Please try again.';
        }
    }

    /**
     * Extract intent (dates, data_types, people) from user message for targeted data fetching.
     * Uses Claude 3.5 Haiku for speed. Returns ['is_general' => true] on failure.
     *
     * @param  string  $userMessage  The latest user message.
     * @param  array<int, array{role: string, content: string}>  $conversationContext  Recent messages for context.
     * @return array{is_general: bool, dates?: array<string>, data_types?: array<string>, people?: array<string>}
     */
    public function extractIntent(string $userMessage, array $conversationContext = []): array
    {
        $default = ['is_general' => true];

        if (empty($this->apiKey)) {
            Log::debug('TessaAIService: extractIntent skipped, no API key');
            return $default;
        }

        $today = \Carbon\Carbon::now('Asia/Kolkata');
        $systemPrompt = str_replace(
            ['{today_date}', '{today_day}', '{today_time}'],
            [$today->format('Y-m-d'), $today->format('l'), $today->format('g:i A')],
            self::INTENT_EXTRACTION_PROMPT
        );

        $contextMessages = [];
        $recent = array_slice($conversationContext, -6);
        foreach ($recent as $m) {
            $contextMessages[] = [
                'role' => $m['role'] === 'user' ? 'user' : 'assistant',
                'content' => $m['content'] ?? '',
            ];
        }
        if (empty($contextMessages) || end($contextMessages)['content'] !== trim($userMessage)) {
            $contextMessages[] = ['role' => 'user', 'content' => trim($userMessage) ?: 'hi'];
        }

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $contextMessages
            ),
        ];

        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');

            $content = preg_replace('/^```(?:json)?\s*/', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                Log::warning('TessaAIService: extractIntent invalid JSON', ['raw' => $content]);
                return $default;
            }

            $intent = [
                'is_general' => (bool) ($decoded['is_general'] ?? true),
                'dates' => array_values(array_unique(array_filter((array) ($decoded['dates'] ?? [])))),
                'data_types' => array_values(array_unique(array_filter((array) ($decoded['data_types'] ?? [])))),
                'people' => array_values(array_unique(array_filter((array) ($decoded['people'] ?? [])))),
            ];

            $sd = $decoded['send_dm'] ?? null;
            if (is_array($sd) && ! empty($sd['target_person']) && isset($sd['message']) && trim((string) $sd['message']) !== '') {
                $intent['send_dm'] = [
                    'target_person' => trim((string) $sd['target_person']),
                    'message' => trim((string) $sd['message']),
                ];
            }

            $intent['sign_off'] = (bool) ($decoded['sign_off'] ?? false);
            $intent['confirm_sign_off'] = (bool) ($decoded['confirm_sign_off'] ?? false);
            $intent['sign_in'] = (bool) ($decoded['sign_in'] ?? false);
            $intent['pending_work'] = (bool) ($decoded['pending_work'] ?? false);

            $lr = $decoded['leave_request'] ?? null;
            if (is_array($lr) && !empty($lr['type'])) {
                $intent['leave_request'] = [
                    'type' => trim((string) $lr['type']),
                    'start_date' => trim((string) ($lr['start_date'] ?? '')),
                    'end_date' => trim((string) ($lr['end_date'] ?? $lr['start_date'] ?? '')),
                    'reason' => isset($lr['reason']) ? trim((string) $lr['reason']) : null,
                ];
            }
            $intent['leave_balance'] = (bool) ($decoded['leave_balance'] ?? false);

            $lc = $decoded['leave_cancel'] ?? null;
            if (is_array($lc)) {
                $intent['leave_cancel'] = [
                    'date' => !empty($lc['date']) ? trim((string) $lc['date']) : null,
                ];
            }

            Log::debug('TessaAIService: intent extracted', $intent);

            return $intent;
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: extractIntent failed', [
                'message' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    /**
     * Given meeting notes and a list of agenda questions, use AI to extract
     * concise answers for each question. Returns [questionId => answer, ...].
     *
     * @param  string  $notes  The meeting notes / minutes content.
     * @param  array<int, array{id: int, question: string}>  $questions  Agenda questions with their IDs.
     * @return array<int, string>  Map of question ID to extracted answer.
     */
    public function fillAgendaFromNotes(string $notes, array $questions): array
    {
        if (empty($this->apiKey) || empty($questions) || trim($notes) === '') {
            return [];
        }

        $questionList = '';
        foreach ($questions as $q) {
            $questionList .= $q['id'] . '. ' . $q['question'] . "\n";
        }

        $systemPrompt = 'You are a meeting-minutes analyst. You will receive the minutes/notes of a meeting and a numbered list of agenda questions. '
            . 'The minutes are often a multi-person stand-up or huddle summary organised by topic or by person, NOT by question — so the answer to a question is usually spread across several lines. '
            . 'For EACH question, write a clear, CONCISE answer (a short paragraph, ideally 2-4 sentences) that gathers the relevant points from anywhere in the minutes and synthesises them. Summarise rather than transcribe — keep the important specifics (names, key tasks, decisions, blockers) but do not pad, repeat, or list every minor detail. '
            . 'Stand-up questions must aggregate across participants — e.g. "What did you work on?" = briefly combine what each person reported; "Any blockers?" = name the problems, failures, or open questions raised. '
            . 'Make reasonable inferences from context. Only answer "Not discussed" when the minutes genuinely contain NOTHING related to that question — never merely because there is no word-for-word match. '
            . 'Return ONLY a valid JSON object mapping each question number (as a string key) to its answer string. No markdown, no commentary.';

        $userContent = "MEETING MINUTES:\n" . $notes . "\n\nAGENDA QUESTIONS:\n" . $questionList . "\nReturn a JSON object mapping each question number to a thorough answer synthesised from the minutes.";

        // Scale the output budget to the agenda size: a flat 3000 truncated the
        // JSON for 6+ question agendas with rich notes, and a truncated response
        // is unparseable → 0 answers written. Cap at 8000.
        $maxTokens = min(8000, 1500 + count($questions) * 800);

        $payload = [
            'model' => self::AGENDA_MODEL,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        try {
            $client = new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);

            $attemptPost = function (array $body) use ($client) {
                return $client->post(self::OPENROUTER_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                        'X-Title' => 'Tessa',
                    ],
                    'json' => $body,
                ]);
            };

            // Two attempts: the agenda model occasionally returns an empty or
            // garbled body (e.g. just "{") that yields no answers — a single
            // retry clears that transient case so Save Minutes fills in one shot.
            $content = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $response = $attemptPost($payload);
                } catch (\GuzzleHttp\Exception\ClientException $ce) {
                    if ($ce->getResponse() && $ce->getResponse()->getStatusCode() === 402) {
                        // Keep a workable budget — shrinking to a few hundred
                        // tokens just truncates the JSON and returns 0 answers.
                        $payload['max_tokens'] = min($maxTokens, 2000);
                        Log::warning('TessaAIService: fillAgendaFromNotes 402, retrying with smaller max_tokens');
                        $response = $attemptPost($payload);
                    } else {
                        throw $ce;
                    }
                }

                $body = json_decode((string) $response->getBody(), true);
                $content = trim($body['choices'][0]['message']['content'] ?? '');
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```\s*$/', '', $content);

                $decoded = json_decode($content, true);
                // Salvage the complete "<id>": "<answer>" pairs from a truncated
                // or partially-garbled response instead of discarding everything.
                if (! is_array($decoded)) {
                    $decoded = self::salvageAgendaJson($content);
                }

                if (is_array($decoded) && ! empty($decoded)) {
                    $result = [];
                    foreach ($questions as $q) {
                        $answer = $decoded[(string) $q['id']] ?? null;
                        if (is_string($answer) && trim($answer) !== '') {
                            $result[$q['id']] = trim($answer);
                        }
                    }
                    if (! empty($result)) {
                        Log::debug('TessaAIService: fillAgendaFromNotes completed', [
                            'questions_count' => count($questions),
                            'answers_count' => count($result),
                            'attempt' => $attempt,
                        ]);

                        return $result;
                    }
                }
            }

            Log::warning('TessaAIService: fillAgendaFromNotes invalid JSON', ['raw' => $content]);

            return [];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: fillAgendaFromNotes failed', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Best-effort recovery of a truncated/malformed agenda JSON object: pull out
     * every COMPLETE "<id>": "<answer>" pair so a cut-off tail doesn't discard
     * the answers that already finished. Returns [] when nothing usable remains.
     */
    private static function salvageAgendaJson(string $content): array
    {
        if (! preg_match_all('/"(\d+)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($matches as $pair) {
            $value = json_decode('"' . $pair[2] . '"');   // unescape \n, \", \uXXXX…
            if (is_string($value) && trim($value) !== '') {
                $out[$pair[1]] = $value;
            }
        }

        return $out;
    }

    /**
     * Grammar/spelling correction. Returns the corrected text on success, or
     * null on any failure (caller decides whether to fall back or surface).
     */
    public function correctGrammar(string $text): ?string
    {
        $text = trim($text);
        if (empty($this->apiKey) || $text === '') {
            return null;
        }

        $systemPrompt = 'You are a grammar and spelling editor. Correct the user\'s text for grammar, spelling, punctuation, capitalization, and obvious word-choice errors. PRESERVE the original meaning, tone, and language. Do NOT translate. Do NOT rewrite or paraphrase. Do NOT add new content. Do NOT add quotes around the result. If the text is already correct, return it unchanged. Return ONLY the corrected text — no explanation, no markdown, no preamble.';

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ],
        ];

        try {
            $client = new Client(['timeout' => 20, 'connect_timeout' => 8]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $corrected = trim($body['choices'][0]['message']['content'] ?? '');

            // Defensive: model occasionally wraps output in code fences/quotes.
            $corrected = preg_replace('/^```[a-z]*\s*/i', '', $corrected);
            $corrected = preg_replace('/\s*```\s*$/', '', $corrected);
            $corrected = trim($corrected, "\"'`\n\r\t ");

            if ($corrected === '') {
                return null;
            }

            return $corrected;
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: correctGrammar failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse a bank statement (text content) and extract transactions.
     * Uses Claude Opus 4.6 for accurate document understanding.
     *
     * @param  string  $statementText  Raw text content of the bank statement (from PDF/CSV).
     * @return array  Array of parsed transactions, each with: date, description, reference, amount, type (credit/debit), balance.
     */
    public function parseBankStatement(string $statementText): array
    {
        if (empty($this->apiKey) || trim($statementText) === '') {
            return [];
        }

        // Split large statements into chunks to avoid AI timeout
        $lines = explode("\n", $statementText);
        $header = '';
        $maxLinesPerChunk = 150;

        // Keep first few lines as header context (column names etc.)
        if (count($lines) > $maxLinesPerChunk) {
            $headerLines = min(5, count($lines));
            $header = implode("\n", array_slice($lines, 0, $headerLines));
            $dataLines = array_slice($lines, $headerLines);
            $chunks = array_chunk($dataLines, $maxLinesPerChunk);
            Log::info('parseBankStatement: splitting into chunks', [
                'totalLines' => count($lines),
                'chunks' => count($chunks),
                'headerLines' => $headerLines,
            ]);
        } else {
            $chunks = [$lines];
        }

        $allTransactions = [];

        foreach ($chunks as $i => $chunkLines) {
            $chunkText = $header ? ($header . "\n" . implode("\n", $chunkLines)) : implode("\n", $chunkLines);
            Log::info('parseBankStatement: processing chunk', ['chunk' => $i + 1, 'of' => count($chunks), 'textLength' => strlen($chunkText)]);

            $result = $this->parseBankStatementChunk($chunkText);
            if (! empty($result)) {
                $allTransactions = array_merge($allTransactions, $result);
            }
        }

        Log::info('parseBankStatement: completed all chunks', ['totalTransactions' => count($allTransactions)]);

        return $allTransactions;
    }

    private function parseBankStatementChunk(string $statementText): array
    {
        $systemPrompt = 'You are a bank statement parser. Extract ALL transactions from the provided bank statement text. Return ONLY valid JSON array with no markdown or explanation.

Each transaction object must have:
- "date": "YYYY-MM-DD" (convert any date format to this)
- "description": "transaction description/narration"
- "reference": "reference number or null if not available"
- "amount": numeric value (always positive)
- "type": "debit" or "credit"
- "balance": closing balance after this transaction (numeric, or null if not shown)

Rules:
- Parse every single transaction row, do not skip any
- Convert all dates to YYYY-MM-DD format
- Amount should be a positive number regardless of debit/credit
- Identify debit vs credit from column headers, DR/CR indicators, or +/- signs
- If the statement has opening/closing balance rows, skip those (only include transactions)
- Handle various bank statement formats (Indian banks, international banks, CSV-style, tabular)
- If a description spans multiple lines, combine into one string';

        $payload = [
            'model' => self::EXTRACTION_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Parse this bank statement and extract all transactions:\n\n" . $statementText],
            ],
            'temperature' => 0.1,
            'max_tokens' => 8192,
        ];

        try {
            $client = new Client([
                'timeout' => 180,
                'connect_timeout' => 10,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');

            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                Log::warning('TessaAIService: parseBankStatementChunk invalid JSON', ['raw' => substr($content, 0, 500)]);
                return [];
            }

            Log::debug('TessaAIService: parseBankStatementChunk completed', [
                'transactions_count' => count($decoded),
            ]);

            return $decoded;
        } catch (\Throwable $e) {
            Log::error('TessaAIService: parseBankStatementChunk failed', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Read an invoice file and extract key details using AI.
     * Uses Haiku for speed since invoice extraction is simpler.
     *
     * @param  string  $invoiceText  Text content extracted from invoice file.
     * @return array{vendor: string|null, amount: float|null, date: string|null}
     */
    public function extractInvoiceDetails(string $invoiceText): array
    {
        $default = ['vendor' => null, 'service' => null, 'amount' => null, 'currency' => 'INR', 'date' => null, 'invoice_number' => null];

        if (empty($this->apiKey) || trim($invoiceText) === '') {
            return $default;
        }

        $systemPrompt = 'Extract invoice details from the provided text. Return ONLY valid JSON with no markdown:
{
  "vendor": "company/vendor name",
  "service": "brief description of what was billed",
  "amount": 1234.56,
  "currency": "INR",
  "date": "YYYY-MM-DD",
  "invoice_number": "INV-12345"
}
If a field cannot be determined, set it to null. Amount should be the total/grand total as a number. currency should be the 3-letter code (INR, USD, EUR etc). invoice_number is the invoice number/ID from the document. service should be a short 2-5 word phrase summarizing what was billed (e.g. "Cloud hosting", "Marketing services", "Software subscription", "Office rent"). Use the line items, description, or service description fields.';

        $payload = [
            'model' => self::EXTRACTION_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Extract details from this invoice:\n\n" . $invoiceText],
            ],
            'temperature' => 0.1,
        ];

        try {
            $client = new Client(['timeout' => 15, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $default;
            }

            return [
                'vendor' => $decoded['vendor'] ?? null,
                'service' => $decoded['service'] ?? null,
                'amount' => isset($decoded['amount']) ? (float) $decoded['amount'] : null,
                'currency' => $decoded['currency'] ?? 'INR',
                'date' => $decoded['date'] ?? null,
                'invoice_number' => $decoded['invoice_number'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: extractInvoiceDetails failed', ['message' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Extract candidate basics from résumé text (Hiring/ATS). Same engine as
     * extractInvoiceDetails — premium EXTRACTION_MODEL, low temp, JSON-only.
     * Returns name/email/phone/experience_years/skills; nulls when unknown.
     */
    public function extractResumeDetails(string $resumeText): array
    {
        $default = ['name' => null, 'email' => null, 'phone' => null, 'experience_years' => null, 'skills' => null];

        if (empty($this->apiKey) || trim($resumeText) === '') {
            return $default;
        }

        $systemPrompt = 'Extract candidate details from the résumé text. Return ONLY valid JSON with no markdown:
{
  "name": "full name",
  "email": "email address",
  "phone": "phone number",
  "experience_years": 3.5,
  "skills": ["React", "TypeScript", "Node.js"]
}
If a field cannot be determined, set it to null (skills to []). name is the candidate full name. email is their primary email. phone is their contact number (keep country code/format). experience_years is total professional experience as a number (estimate from work history if not explicitly stated; null if fresher/unknown). skills is an array of their key technical/professional skills (max 15).';

        $payload = [
            'model' => self::EXTRACTION_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Extract details from this résumé:\n\n" . $resumeText],
            ],
            'temperature' => 0.1,
        ];

        try {
            $client = new Client(['timeout' => 20, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $default;
            }

            $skills = $decoded['skills'] ?? null;
            if (is_array($skills)) {
                $skills = implode(', ', array_filter(array_map(fn ($s) => trim((string) $s), $skills)));
            }

            return [
                'name' => $decoded['name'] ?? null,
                'email' => $decoded['email'] ?? null,
                'phone' => isset($decoded['phone']) ? (string) $decoded['phone'] : null,
                'experience_years' => isset($decoded['experience_years']) && is_numeric($decoded['experience_years'])
                    ? (float) $decoded['experience_years'] : null,
                'skills' => $skills ?: null,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: extractResumeDetails failed', ['message' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Draft an interview-invite email (Hiring/ATS). This is GENERATION, so it
     * uses gemini-flash (not the extraction model). Returns {subject, body};
     * falls back to a simple templated email when the key is missing or the AI
     * errors, so the UI is never blocked.
     *
     * @param  array  $ctx  candidate_name, role_title, round, date_label,
     *                      time_label, meet_link, company
     */
    public function draftInterviewEmail(array $ctx): array
    {
        $name = trim((string) ($ctx['candidate_name'] ?? '')) ?: 'there';
        $role = trim((string) ($ctx['role_title'] ?? '')) ?: 'the role';
        $round = ($ctx['round'] ?? 'technical') === 'hr' ? 'HR' : 'technical';
        $company = trim((string) ($ctx['company'] ?? '')) ?: 'InnovFix';
        $when = trim(((string) ($ctx['date_label'] ?? '')) . ' ' . ((string) ($ctx['time_label'] ?? '')));
        $meet = trim((string) ($ctx['meet_link'] ?? ''));

        $fallback = [
            'subject' => "{$company} — {$round} interview for {$role}",
            'body' => "Hi {$name},\n\nThank you for your interest in the {$role} role at {$company}. "
                . "We'd like to invite you to a {$round} interview"
                . ($when !== '' ? " on {$when}" : '') . ".\n\n"
                . ($meet !== '' ? "Google Meet: {$meet}\n\n" : '')
                . "Please let us know if this works for you.\n\nBest regards,\n{$company} Hiring Team",
        ];

        if (empty($this->apiKey)) {
            return $fallback;
        }

        $details = "Candidate: {$name}\nRole: {$role}\nRound: {$round} interview\nCompany: {$company}"
            . ($when !== '' ? "\nWhen: {$when}" : '')
            . ($meet !== '' ? "\nGoogle Meet link: {$meet}" : '');

        $systemPrompt = 'You write a short, warm, professional interview-invitation email from a company hiring team to a candidate. Return ONLY valid JSON with no markdown:
{"subject": "…", "body": "…"}
The body must address the candidate by name, state the role and that it is the given interview round, include the date/time and Google Meet link if provided, ask them to confirm availability, and sign off as the company hiring team. Keep it under 130 words. Plain text in the body (use \n for line breaks), no bracketed placeholders.';

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Write the email for these details:\n\n{$details}"],
            ],
            'temperature' => 0.4,
        ];

        try {
            $client = new Client(['timeout' => 20, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded) || empty($decoded['body'])) {
                return $fallback;
            }

            return [
                'subject' => trim((string) ($decoded['subject'] ?? '')) ?: $fallback['subject'],
                'body' => trim((string) $decoded['body']),
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: draftInterviewEmail failed', ['message' => $e->getMessage()]);
            return $fallback;
        }
    }

    /**
     * Extract invoice details from an image using vision API.
     */
    public function extractInvoiceDetailsFromImage(string $base64Image, string $mimeType = 'image/jpeg'): array
    {
        $default = ['vendor' => null, 'service' => null, 'amount' => null, 'currency' => 'INR', 'date' => null, 'invoice_number' => null];

        if (empty($this->apiKey)) {
            return $default;
        }

        $systemPrompt = 'Extract invoice details from the provided invoice image. Return ONLY valid JSON with no markdown:
{
  "vendor": "company/vendor name",
  "service": "brief description of what was billed",
  "amount": 1234.56,
  "currency": "INR",
  "date": "YYYY-MM-DD",
  "invoice_number": "INV-12345"
}
If a field cannot be determined, set it to null. Amount should be the total/grand total as a number. currency should be the 3-letter code (INR, USD, EUR etc). invoice_number is the invoice number/ID from the document. service should be a short 2-5 word phrase summarizing what was billed (e.g. "Cloud hosting", "Marketing services", "Software subscription", "Office rent"). Use the line items or service description.';

        $payload = [
            'model' => self::EXTRACTION_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64Image}",
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Extract the vendor name, total amount, date, and invoice number from this invoice image.',
                    ],
                ]],
            ],
            'temperature' => 0.1,
        ];

        try {
            $client = new Client(['timeout' => 30, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $default;
            }

            return [
                'vendor' => $decoded['vendor'] ?? null,
                'service' => $decoded['service'] ?? null,
                'amount' => isset($decoded['amount']) ? (float) $decoded['amount'] : null,
                'currency' => $decoded['currency'] ?? 'INR',
                'date' => $decoded['date'] ?? null,
                'invoice_number' => $decoded['invoice_number'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: extractInvoiceDetailsFromImage failed', ['message' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Match an invoice against bank transactions using AI fuzzy matching.
     * Returns the best matching transaction ID and confidence score.
     *
     * @param  array  $invoice  Invoice details (vendor_name, amount, invoice_date).
     * @param  array  $transactions  Array of unmatched bank transactions.
     * @return array{transaction_id: int|null, confidence: float, reason: string}
     */
    public function matchInvoiceToTransaction(array $invoice, array $transactions): array
    {
        $default = ['transaction_id' => null, 'confidence' => 0, 'reason' => 'No match found'];

        if (empty($this->apiKey) || empty($transactions)) {
            return $default;
        }

        $txList = '';
        foreach ($transactions as $tx) {
            $txList .= "ID:{$tx['id']} | Date:{$tx['transaction_date']} | Amount:₹{$tx['amount']} | Desc:{$tx['description']} | Type:{$tx['type']}\n";
        }

        $systemPrompt = 'You match invoices to bank transactions. Given an invoice and a list of bank transactions, find the best match. Return ONLY valid JSON:
{
  "transaction_id": 123,
  "confidence": 85.5,
  "reason": "Amount matches exactly, vendor name similar to transaction description, dates within 3 days"
}

Matching rules:
- Amount: exact match = high confidence, within 5% = medium, >5% difference = low
- Date: same day = high, within 7 days = medium, >7 days = low
- Vendor/Description: fuzzy match on company name (e.g. "Amazon Web Services" matches "AWS", "Google Cloud" matches "GOOGLE*GCP")
- Only match debit transactions (payments out)
- confidence: 0-100 scale. Only return a match if confidence >= 60
- If no good match exists, set transaction_id to null and confidence to 0';

        $invoiceInfo = "Vendor: {$invoice['vendor_name']}\nAmount: ₹{$invoice['amount']}\nDate: {$invoice['invoice_date']}";

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "INVOICE:\n{$invoiceInfo}\n\nBANK TRANSACTIONS:\n{$txList}\n\nFind the best matching transaction."],
            ],
            'temperature' => 0.1,
        ];

        try {
            $client = new Client(['timeout' => 15, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $default;
            }

            $txId = $decoded['transaction_id'] ?? null;
            $confidence = (float) ($decoded['confidence'] ?? 0);

            // Validate that returned transaction_id actually exists in our list
            if ($txId !== null) {
                $validIds = array_column($transactions, 'id');
                if (! in_array($txId, $validIds, false)) {
                    Log::warning('TessaAIService: matchInvoice returned invalid transaction_id', ['id' => $txId]);
                    return $default;
                }
            }

            Log::debug('TessaAIService: matchInvoiceToTransaction', [
                'invoice_vendor' => $invoice['vendor_name'],
                'matched_id' => $txId,
                'confidence' => $confidence,
            ]);

            return [
                'transaction_id' => $confidence >= 60 ? $txId : null,
                'confidence' => $confidence,
                'reason' => $decoded['reason'] ?? '',
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: matchInvoiceToTransaction failed', ['message' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Summarize a conversation into a concise 1-2 sentence response for a follow-up.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function summarizeConversation(array $messages): string
    {
        if (empty($this->apiKey)) {
            return 'Completed.';
        }

        $conversation = '';
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = trim($m['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $label = $role === 'user' ? 'User' : 'Tessa';
            $conversation .= "{$label}: {$content}\n\n";
        }

        $systemPrompt = 'You summarize a short conversation between a user and Tessa (an AI assistant) about a follow-up task. Return ONLY a concise 1-2 sentence summary of what was discussed and the outcome. No preamble, no quotes. Example: "User confirmed KPIs are updated. Will share the report by EOD."';

        $payload = [
            'model' => 'google/gemini-2.5-flash',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Summarize this conversation:\n\n{$conversation}"],
            ],
        ];

        try {
            $client = new Client([
                'timeout' => 15,
                'connect_timeout' => 5,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');

            return $content ?: 'Completed.';
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: summarizeConversation failed', [
                'message' => $e->getMessage(),
            ]);
            return 'Completed.';
        }
    }

    /**
     * Quick AI call using Haiku for fast structured responses.
     */
    public function quickAi(string $systemPrompt, string $userMessage, ?float $temperature = null): string
    {
        if (empty($this->apiKey)) {
            return '';
        }

        try {
            $payload = [
                'model' => 'google/gemini-2.5-flash',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ];
            // Callers needing deterministic structured output (e.g. the email
            // classifier re-run every cron tick) pass temperature 0 so the same
            // input doesn't flip verdicts between runs.
            if ($temperature !== null) {
                $payload['temperature'] = $temperature;
            }

            $client = new Client(['timeout' => 30, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            return trim($body['choices'][0]['message']['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: quickAi failed', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Month-end KPI verdict for ONE KPI. Given the KPI (name + monthly target +
     * weight) and the manager's weekly tracking notes for the month, return a
     * short summary plus a best-effort estimate of how much of the target was met.
     *
     * @param  array<int,string>  $weeklyNotes
     * @return array{summary:string, percentage_met:?int, status:string}
     */
    public function summarizeKpiMonth(string $kpiName, ?string $target, ?int $weight, array $weeklyNotes): array
    {
        $default = ['summary' => '', 'percentage_met' => null, 'status' => 'unknown'];
        $notes = array_values(array_filter(array_map('trim', $weeklyNotes), fn ($n) => $n !== ''));
        if (empty($this->apiKey) || empty($notes)) {
            return $default;
        }

        $systemPrompt = 'You are a performance analyst. You are given ONE KPI with its monthly target and a manager\'s weekly progress notes for that month. Judge how the month went for this KPI. Return ONLY valid JSON, no markdown:
{
  "summary": "2-3 sentence factual summary of the month for this KPI",
  "percentage_met": integer 0-100 (your best estimate of how much of the target was achieved; use null only if the notes give no basis at all),
  "status": "met" | "partial" | "missed" | "unknown"
}
Rules: "met" = target achieved (>=100% or clearly hit); "partial" = meaningful progress but short of target; "missed" = little or no progress; "unknown" = notes too vague to judge. Base the verdict only on the notes versus the target — never invent numbers.';

        $userMessage = "KPI: {$kpiName}\n"
            . 'Monthly target: ' . ($target ?: 'not specified') . "\n"
            . ($weight ? "Scorecard weight: {$weight}\n" : '')
            . "\nWeekly notes (one per week, in order):\n- " . implode("\n- ", $notes);

        try {
            $client = new Client(['timeout' => 30, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => [
                    'model' => 'google/gemini-2.5-flash',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'temperature' => 0.2,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```\s*$/', '', $content);
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $default;
            }

            $pct = $decoded['percentage_met'] ?? null;
            $status = strtolower((string) ($decoded['status'] ?? 'unknown'));
            if (! in_array($status, ['met', 'partial', 'missed', 'unknown'], true)) {
                $status = 'unknown';
            }

            return [
                'summary' => trim((string) ($decoded['summary'] ?? '')),
                'percentage_met' => ($pct === null || $pct === '') ? null : max(0, (int) round((float) $pct)),
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: summarizeKpiMonth failed', ['message' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Person-level overall month narrative, built from the per-KPI verdicts.
     *
     * @param  array<int,array{name:string,summary:string,percentage_met:?int,status:string}>  $kpiResults
     */
    public function summarizeKpiMonthOverall(string $personName, array $kpiResults): string
    {
        if (empty($this->apiKey) || empty($kpiResults)) {
            return '';
        }
        $lines = [];
        foreach ($kpiResults as $r) {
            $pct = ($r['percentage_met'] ?? null) !== null ? " ({$r['percentage_met']}% of target, {$r['status']})" : " ({$r['status']})";
            $lines[] = "- {$r['name']}{$pct}: " . (($r['summary'] ?? '') ?: 'no summary');
        }
        $system = 'You are a performance analyst. Given a person\'s per-KPI results for the month, write a brief 3-4 sentence overall summary of their month: the headline, what went well, and what fell short. Be factual and concise. Plain text only — no markdown, no headers.';
        $user = "Person: {$personName}\nPer-KPI results this month:\n" . implode("\n", $lines);

        return $this->quickAi($system, $user, 0.3);
    }

    /**
     * Generate personalized checkin questions for pending task updates using Sonnet 4.6.
     *
     * @param  array  $items  Array of ['title', 'date_label', 'progress', 'last_note', 'blocker_status']
     * @return array<int, string>  Question per item, same order
     */
    public function generateCheckinQuestions(array $items): array
    {
        if (empty($this->apiKey) || empty($items)) {
            return [];
        }

        $taskList = '';
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $lastNote = $item['last_note'] ?? null;
            $progress = $item['progress'] ?? 0;
            $blocker = $item['blocker_status'] ?? 'no_update';
            $context = $lastNote
                ? "last update: \"{$lastNote}\" ({$progress}%)"
                : 'no previous update';
            if ($blocker === 'blocked') {
                $context .= ' [BLOCKED]';
            }
            $taskList .= "{$n}. \"{$item['title']}\" — {$context} — for: {$item['date_label']}\n";
        }

        $systemPrompt = 'You are Tessa, a friendly AI work assistant. Generate a short, personalized checkin question (1-2 sentences max) for each pending task update below. Be specific — reference what they mentioned last time if available. Don\'t be generic. Keep it warm and direct. Return ONLY a JSON array of strings, one question per task, same order. No markdown, no explanation.';

        $userMessage = "Tasks needing update:\n{$taskList}";

        try {
            $client = new Client(['timeout' => 30, 'connect_timeout' => 5]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => [
                    'model' => 'google/gemini-2.5-flash',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'temperature' => 0.7,
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $questions = json_decode($content, true);

            if (is_array($questions) && count($questions) === count($items)) {
                return $questions;
            }

            Log::warning('TessaAIService: generateCheckinQuestions returned unexpected format', ['content' => $content]);
            return [];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: generateCheckinQuestions failed', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Multi-turn chat with a custom system prompt — bypasses Tessa's global persona.
     * Used by feature-specific assistants (e.g. Timesheet Assistant) that need their
     * own focused prompt and full conversation history.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function customChat(string $systemPrompt, array $messages, string $model = 'google/gemini-2.5-flash'): string
    {
        if (empty($this->apiKey)) {
            return 'AI is not configured.';
        }

        $payload = [
            'model' => $model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
        ];

        try {
            $client = new Client(['timeout' => 60, 'connect_timeout' => 10]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => $payload,
            ]);
            $body = json_decode((string) $response->getBody(), true);
            return trim($body['choices'][0]['message']['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('TessaAIService::customChat failed', ['err' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * AI call using Sonnet for medium-complexity tasks.
     */
    public function mediumAi(string $systemPrompt, string $userMessage): string
    {
        if (empty($this->apiKey)) {
            return '';
        }

        try {
            $client = new Client(['timeout' => 45, 'connect_timeout' => 10]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa',
                ],
                'json' => [
                    'model' => 'google/gemini-2.5-flash',
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            return trim($body['choices'][0]['message']['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: mediumAi failed', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Analyze a log entry for the Logs feature in a single AI pass:
     *  - decide whether it is substantive enough to record (`log`)
     *  - fix grammar/spelling/punctuation while preserving meaning (`content`)
     *  - classify into exactly one category (`category`)
     *
     * Always returns a usable array; falls back to heuristics if the AI is
     * unavailable so entries are never silently lost.
     *
     * @return array{log: bool, category: string, content: string}
     */
    public function analyzeLogEntry(string $text, string $feature = 'logs', ?int $userId = null): array
    {
        $text = trim($text);

        $fallback = [
            'log' => $this->isLikelyMeaningfulLog($text),
            'category' => $this->heuristicLogCategory($text),
            'content' => $text,
        ];

        if ($text === '' || empty($this->apiKey)) {
            return $fallback;
        }

        $systemPrompt = <<<'SYS'
You process short personal/work log entries for a founder's activity journal. For each entry do three things and return JSON only.

1. "log" (boolean): true if the entry records something worth keeping — an observation, update, decision, problem, idea, meeting, plan, or reflection. Set false ONLY for empty filler with no informational value: bare greetings ("hi", "hello", "good morning"), tests ("test", "asdf"), or single acknowledgements ("ok", "thanks"). When in doubt, prefer true.

2. "content" (string): the entry rewritten with correct grammar, spelling, punctuation, and capitalization. PRESERVE the original meaning, tone, language, names, and numbers. Do NOT translate, paraphrase, summarize, or add new information. If already clean, return it unchanged. If "log" is false, just echo the original text.

3. "category" (string): exactly one of:
   - note: general observation, status, or fact
   - decision: decided something or committed to an action
   - problem: something broken, blocking, failing, delayed, or a fire
   - idea: a suggestion, experiment, "what if", or brainstorm not yet decided
   - meeting: a meeting, call, standup, or sync with people

Respond with JSON only, e.g. {"log":true,"content":"...","category":"note"}.
SYS;

        try {
            $client = new Client(['timeout' => 20, 'connect_timeout' => 8]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa Logs',
                ],
                'json' => [
                    'model' => self::LOGS_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    // Ask OpenRouter to include token counts + actual cost in the response.
                    'usage' => ['include' => true],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $this->recordUsage($feature, self::LOGS_MODEL, $body['usage'] ?? null, $userId);

            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                $cat = strtolower(trim((string) ($decoded['category'] ?? '')));
                $clean = trim((string) ($decoded['content'] ?? ''));
                $log = array_key_exists('log', $decoded) ? (bool) $decoded['log'] : true;

                return [
                    'log' => $log,
                    'category' => LogEntry::isValidCategory($cat) ? $cat : $this->heuristicLogCategory($text),
                    'content' => $clean !== '' ? $clean : $text,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: analyzeLogEntry failed', ['message' => $e->getMessage()]);
        }

        return $fallback;
    }

    /**
     * Parse natural-language text from the Logs composer into task fields.
     *
     * @return array{title: string, assignee: ?string, due_date: ?string, priority: string}
     */
    public function parseTaskFromText(string $text, ?int $userId = null, ?string $assignerName = null): array
    {
        $text = trim($text);
        $fallback = [
            'title' => $text,
            'assignee' => null,
            'due_date' => null,
            'priority' => 'medium',
        ];

        if ($text === '' || empty($this->apiKey)) {
            return $fallback;
        }

        $today = \Carbon\Carbon::now('Asia/Kolkata');
        $todayStr = $today->format('Y-m-d');
        $dayName = $today->format('l');
        $assignerLine = $assignerName
            ? "The assigner is {$assignerName}. If they say \"me\", \"myself\", or \"assign to me\", assignee is \"{$assignerName}\"."
            : '';

        $systemPrompt = <<<PROMPT
You extract task assignment details from one short sentence typed in a work log composer.
Today is {$todayStr} ({$dayName}) in Asia/Kolkata (IST).
{$assignerLine}

Return ONLY valid JSON:
{
  "title": "Clear task title without assignee names or due-date phrases (max 12 words)",
  "assignee": "First name or full name of the person to assign to, or null if not mentioned",
  "due_date": "YYYY-MM-DD deadline, or null if not mentioned. Resolve relative dates: tomorrow, Friday, next Monday, end of week (Friday), etc.",
  "priority": "low|medium|high|urgent — default medium if not stated"
}

Rules:
- assignee is required in the sentence for a non-null value; use null only when no person is named.
- due_date is required in the sentence for a non-null value; use null only when no date/deadline is mentioned.
- Strip filler like "please", "can you", "assign", "task" from title; keep the actionable work description.
PROMPT;

        try {
            $content = $this->quickAi($systemPrompt, $text, 0.1);
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $fallback;
            }

            $title = trim((string) ($decoded['title'] ?? ''));
            $assignee = trim((string) ($decoded['assignee'] ?? ''));
            $dueDate = trim((string) ($decoded['due_date'] ?? ''));
            $priority = strtolower(trim((string) ($decoded['priority'] ?? 'medium')));

            if ($title === '') {
                $title = $text;
            }
            if (! in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
                $priority = 'medium';
            }
            if ($dueDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                $dueDate = '';
            }

            return [
                'title' => mb_substr($title, 0, 255),
                'assignee' => $assignee !== '' ? $assignee : null,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'priority' => $priority,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService::parseTaskFromText failed', ['message' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Parse natural-language text from the Logs composer into leave-request fields.
     *
     * @return array{leave_type: ?string, start_date: ?string, end_date: ?string, reason: ?string, from_time: ?string, to_time: ?string}
     */
    public function parseLeaveFromText(string $text, ?string $gender = null, ?string $userName = null): array
    {
        $text = trim($text);
        $fallback = [
            'leave_type' => null,
            'start_date' => null,
            'end_date' => null,
            'reason' => null,
            'from_time' => null,
            'to_time' => null,
        ];

        if ($text === '') {
            return $fallback;
        }

        // Allowed leave types for this user (gender-aware). Compensate is excluded:
        // it needs an explicit weekday-off + weekend-work pairing the composer can't capture.
        $types = \App\Models\LeaveType::query()
            ->where('is_active', true)
            ->where('slug', '!=', 'compensate')
            ->where(function ($q) use ($gender) {
                $q->whereNull('gender_restricted')->orWhere('gender_restricted', $gender);
            })
            ->get(['slug', 'name', 'is_hourly']);

        $allowedSlugs = $types->pluck('slug')->all();

        if (empty($this->apiKey) || empty($allowedSlugs)) {
            return $fallback;
        }

        $typeLines = $types->map(function ($t) {
            return "- {$t->slug}: {$t->name}".($t->is_hourly ? ' (hourly, needs a time range)' : '');
        })->implode("\n");
        $slugList = implode(', ', $allowedSlugs);
        $who = $userName ?: 'a teammate';

        $today = \Carbon\Carbon::now('Asia/Kolkata');
        $todayStr = $today->format('Y-m-d');
        $dayName = $today->format('l');

        $systemPrompt = <<<PROMPT
You extract a leave request from one short sentence typed in a work log composer by {$who}.
Today is {$todayStr} ({$dayName}) in Asia/Kolkata (IST).

Allowed leave types (use the slug on the left):
{$typeLines}

Return ONLY valid JSON:
{
  "leave_type": "one of: {$slugList} — or null if no leave type is mentioned or implied",
  "start_date": "YYYY-MM-DD, or null if no date. Resolve relative dates: today, tomorrow, next Monday, June 5, etc.",
  "end_date": "YYYY-MM-DD for the last day of a multi-day range, else null for a single day",
  "reason": "short reason if stated, else null",
  "from_time": "HH:MM 24h start time — ONLY for the hourly 'permission' type, else null",
  "to_time": "HH:MM 24h end time — ONLY for the hourly 'permission' type, else null"
}

Rules:
- leave_type MUST be one of the allowed slugs, matching intent (e.g. "wfh"/"work from home" -> wfh, "period leave" -> menstrual, "sick" -> sick). Use null if none clearly applies.
- Map "today" to {$todayStr}. Only set end_date when a clear multi-day range is given.
- Keep reason short (max 12 words); null if not stated.
PROMPT;

        try {
            $content = $this->quickAi($systemPrompt, $text, 0.1);
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
            $decoded = json_decode($content, true);

            if (! is_array($decoded)) {
                return $fallback;
            }

            $slug = strtolower(trim((string) ($decoded['leave_type'] ?? '')));
            if (! in_array($slug, $allowedSlugs, true)) {
                $slug = '';
            }

            $start = trim((string) ($decoded['start_date'] ?? ''));
            $end = trim((string) ($decoded['end_date'] ?? ''));
            $reason = trim((string) ($decoded['reason'] ?? ''));
            $fromTime = trim((string) ($decoded['from_time'] ?? ''));
            $toTime = trim((string) ($decoded['to_time'] ?? ''));

            if ($start !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                $start = '';
            }
            if ($end !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                $end = '';
            }
            if ($fromTime !== '' && ! preg_match('/^\d{1,2}:\d{2}$/', $fromTime)) {
                $fromTime = '';
            }
            if ($toTime !== '' && ! preg_match('/^\d{1,2}:\d{2}$/', $toTime)) {
                $toTime = '';
            }

            return [
                'leave_type' => $slug !== '' ? $slug : null,
                'start_date' => $start !== '' ? $start : null,
                'end_date' => $end !== '' ? $end : null,
                'reason' => $reason !== '' ? mb_substr($reason, 0, 1000) : null,
                'from_time' => $fromTime !== '' ? $fromTime : null,
                'to_time' => $toTime !== '' ? $toTime : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('TessaAIService::parseLeaveFromText failed', ['message' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Analyze a short Slack conversation excerpt (a "Speaker: text" transcript)
     * for the Logs feature: decide if it's worth keeping, clean each line's
     * grammar while preserving speakers, and classify the exchange.
     *
     * @return array{log: bool, category: string, content: string}
     */
    public function analyzeLogConversation(string $transcript, string $feature = 'logs', ?int $userId = null): array
    {
        $transcript = trim($transcript);

        $fallback = [
            'log' => true,
            'category' => $this->heuristicLogCategory($transcript),
            'content' => $transcript,
        ];

        if ($transcript === '' || empty($this->apiKey)) {
            return $fallback;
        }

        $systemPrompt = <<<'SYS'
You process a short Slack conversation excerpt for a founder's activity journal. The input is a transcript with one message per line as "Speaker: message". Return JSON only.

1. "log" (boolean): true if the exchange records something worth keeping — a question and its answer, a decision, a problem, a plan, or a status update. false ONLY if the whole exchange is pure greetings/acknowledgements with no information (e.g. just "hi" / "ok" / "thanks").

2. "content" (string): the same transcript with each line's grammar, spelling, punctuation and capitalization cleaned. KEEP the "Speaker:" prefix on every line and keep exactly one line per message. PRESERVE meaning, tone, language (including Tanglish/Tamil/Hindi — do NOT translate), names and numbers. Do NOT summarize, merge, drop, or add lines.

3. "category" (string): one of note, decision, problem, idea, meeting — judged for the exchange as a whole.

Respond with JSON only, e.g. {"log":true,"content":"You: ...\nYuvanesh: ...","category":"decision"}.
SYS;

        try {
            $client = new Client(['timeout' => 25, 'connect_timeout' => 8]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa Logs',
                ],
                'json' => [
                    'model' => self::LOGS_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $transcript],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'usage' => ['include' => true],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $this->recordUsage($feature, self::LOGS_MODEL, $body['usage'] ?? null, $userId);

            $content = trim($body['choices'][0]['message']['content'] ?? '');
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                $cat = strtolower(trim((string) ($decoded['category'] ?? '')));
                $clean = trim((string) ($decoded['content'] ?? ''));
                $log = array_key_exists('log', $decoded) ? (bool) $decoded['log'] : true;

                return [
                    'log' => $log,
                    'category' => LogEntry::isValidCategory($cat) ? $cat : $this->heuristicLogCategory($transcript),
                    'content' => $clean !== '' ? $clean : $transcript,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: analyzeLogConversation failed', ['message' => $e->getMessage()]);
        }

        return $fallback;
    }

    /**
     * Persist token usage + cost for one AI call so spend can be tracked.
     * Prefers OpenRouter's reported cost; falls back to a token estimate.
     */
    private function recordUsage(string $feature, string $model, ?array $usage, ?int $userId): void
    {
        try {
            $prompt = (int) ($usage['prompt_tokens'] ?? 0);
            $completion = (int) ($usage['completion_tokens'] ?? 0);
            $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));
            $cost = isset($usage['cost'])
                ? (float) $usage['cost']
                : $this->estimateCostUsd($model, $prompt, $completion);

            AiUsageLog::create([
                'user_id' => $userId,
                'feature' => $feature,
                'model' => $model,
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $total,
                'cost_usd' => $cost,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TessaAIService: recordUsage failed', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Rough USD cost estimate from token counts, used only when OpenRouter does
     * not return an explicit cost. Rates are per 1M tokens [input, output].
     */
    private function estimateCostUsd(string $model, int $prompt, int $completion): float
    {
        $rates = [
            'openai/gpt-5.3-chat' => [1.75, 14.0],
            'google/gemini-2.5-flash' => [0.30, 2.50],
            'openai/gpt-5-mini' => [0.25, 2.0],
            'anthropic/claude-sonnet-4-6' => [3.0, 15.0],
            'anthropic/claude-3.5-haiku' => [0.8, 4.0],
            'anthropic/claude-opus-4.6' => [15.0, 75.0],
        ];
        [$in, $out] = $rates[$model] ?? [3.0, 15.0];

        return ($prompt / 1_000_000) * $in + ($completion / 1_000_000) * $out;
    }

    /**
     * Lightweight check used only when the AI is unavailable: filter out
     * obvious greetings/filler so they aren't recorded as log entries.
     */
    private function isLikelyMeaningfulLog(string $text): bool
    {
        $t = strtolower(trim($text));
        if ($t === '' || mb_strlen($t) <= 2) {
            return false;
        }

        $trivial = ['hi', 'hii', 'hiii', 'hey', 'hello', 'yo', 'test', 'testing', 'asdf', 'ok', 'okay', 'k', 'hmm', 'gm', 'gn', 'good morning', 'good night'];

        return ! in_array($t, $trivial, true);
    }

    private function heuristicLogCategory(string $text): string
    {
        $t = ' '.strtolower($text).' ';
        if (preg_match('/(bug|issue|failing|\bfail|broken|\bdown\b|not working|block|stuck|problem|\bfire\b|leak|delay|slip|crash|error|risk|complaint|churn|missed)/', $t)) {
            return LogEntry::CATEGORY_PROBLEM;
        }
        if (preg_match('/(decid|decision|going to|i\'?ll |we\'?ll |let\'?s |approv|pushing |making |finaliz|commit to|\bchose\b|choosing|signed off|green ?light)/', $t)) {
            return LogEntry::CATEGORY_DECISION;
        }
        if (preg_match('/(\bidea\b|what if|maybe we|could we|should we|\btry\b|\btest\b|experiment|thought:|brainstorm|concept|hypothesis|proposal)/', $t)) {
            return LogEntry::CATEGORY_IDEA;
        }
        if (preg_match('/(call with|\bmet\b|meeting|standup|stand-up|spoke|synced|sync with|discussion|reviewed with|1:1|one[ -]on[ -]one|caught up|talked to|off a call)/', $t)) {
            return LogEntry::CATEGORY_MEETING;
        }

        return LogEntry::CATEGORY_NOTE;
    }
}
