<?php

namespace App\Http\Controllers;

use App\Models\AiFirstParticipant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AiFirstController extends Controller
{
    private const CEO_ROLE = 'ceo';

    /**
     * Trackable columns shown as checkboxes on the public AI First table.
     *
     * To add a new tracking column in the future:
     *   1. Add a nullable timestamp column to ai_first_participants (e.g. `chatgpt_activated_at`).
     *   2. Append a new entry to this array with the matching `field` key.
     *
     * That's it — the view, the toggle endpoint, and the stats bar all
     * iterate over this list, so no other code changes are needed.
     */
    public static function columns(): array
    {
        return [
            [
                'key'   => 'claude',
                'label' => 'Claude Subscribed',
                'field' => 'claude_activated_at',
                'hint'  => 'Active Claude Pro / Max / Team subscription',
            ],
            [
                'key'   => 'tessa_mcp',
                'label' => 'Tessa MCP',
                'field' => 'tessa_mcp_connected_at',
                'hint'  => 'Connected the Tessa MCP server to Claude.ai (OAuth token issued)',
            ],
            [
                'key'   => 'slack',
                'label' => 'Slack',
                'field' => 'slack_connected_at',
                'hint'  => 'Connected Tessa to your Slack account',
            ],
            [
                'key'   => 'google_drive',
                'label' => 'Google Drive',
                'field' => 'google_drive_connected_at',
                'hint'  => 'Granted Tessa Drive (read) access via Google OAuth',
            ],
            [
                'key'   => 'google_calendar',
                'label' => 'Google Calendar',
                'field' => 'google_calendar_connected_at',
                'hint'  => 'Granted Tessa Calendar access via Google OAuth',
            ],
            [
                'key'   => 'gmail',
                'label' => 'Gmail',
                'field' => 'gmail_connected_at',
                'hint'  => 'Granted Tessa Gmail (read) access via Google OAuth',
            ],
            [
                'key'             => 'exam',
                'label'           => 'Assessment Cleared',
                'field'           => 'exam_passed_at',
                'hint'            => 'Cleared the 1-on-1 assessment administered by one of the 8 assessors',
                'exempt_for_conductors' => true,
            ],
            // Future examples (uncomment + add DB column to enable):
            // ['key' => 'chatgpt', 'label' => 'ChatGPT Plus', 'field' => 'chatgpt_activated_at', 'hint' => 'ChatGPT Plus subscription'],
            // ['key' => 'cursor',  'label' => 'Cursor',       'field' => 'cursor_activated_at',  'hint' => 'Cursor IDE installed and signed in'],
        ];
    }

    /**
     * Auto-mark Tessa MCP as connected for any participant whose Tessa user
     * has a non-revoked OAuth access token. Runs on every /ai-first page
     * load — Tessa MCP is a NEW action (only set up for AI First), so
     * auto-detect here saves clicks.
     *
     * Slack / Google Drive / Calendar / Gmail are deliberately NOT
     * auto-detected: most people connected those months ago for general
     * Tessa usage, so auto-marking them would inflate AI First numbers.
     * Those columns stay manual (honor system).
     */
    private function autoSyncIntegrations(): void
    {
        $mcpUserIds = DB::table('oauth_access_tokens')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->pluck('user_id')
            ->unique()
            ->all();

        if (! empty($mcpUserIds)) {
            AiFirstParticipant::whereIn('user_id', $mcpUserIds)
                ->whereNull('tessa_mcp_connected_at')
                ->update(['tessa_mcp_connected_at' => now()]);
        }
    }

    /**
     * Public table — every name is a row, every tracked tool is a column.
     * People click any checkbox in their row to mark it activated.
     * Trust-based, no login required.
     */
    public function index()
    {
        $this->autoSyncIntegrations();

        $participants = AiFirstParticipant::orderBy('squad_num')
            ->orderByRaw("FIELD(role_in_squad,'mentor','associate','mentee')")
            ->orderBy('name')
            ->get();

        $columns = self::columns();
        $stats = $this->computeStats($participants, $columns);

        $squadMentors = $participants->where('role_in_squad', 'mentor')->mapWithKeys(fn ($m) => [$m->squad_num => $m->name]);

        // Disable HTTP caching so design/CSS updates show up immediately on
        // every device — the page is small and re-rendered server-side, so
        // there's no perf cost. This avoids stale mobile-cache surprises.
        return response()
            ->view('ai-first.index', compact('participants', 'columns', 'stats', 'squadMentors'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /** Toggle a single column's activation for one participant. Public, AJAX. */
    public function toggle(Request $request, int $participant)
    {
        $columnKeys = array_column(self::columns(), 'key');
        $validated = $request->validate([
            'column'    => 'required|string|in:' . implode(',', $columnKeys),
            'activated' => 'required|boolean',
        ]);

        $col = collect(self::columns())->firstWhere('key', $validated['column']);
        $p = AiFirstParticipant::findOrFail($participant);
        $p->{$col['field']} = $validated['activated'] ? now() : null;

        // Legacy bookkeeping: if Claude is being unmarked, also clear plan/notes.
        if ($col['key'] === 'claude' && ! $validated['activated']) {
            $p->claude_plan = null;
            $p->claude_notes = null;
        }
        $p->save();

        $all = AiFirstParticipant::all();
        $stats = $this->computeStats($all, self::columns());

        return response()->json([
            'ok'           => true,
            'id'           => $p->id,
            'column'       => $col['key'],
            'activated'    => $p->{$col['field']} !== null,
            'activated_at' => $p->{$col['field']}?->setTimezone('Asia/Kolkata')->format('d M, h:i A'),
            'stats'        => $stats,
        ]);
    }

    private function computeStats($participants, array $columns): array
    {
        $total = $participants->count();
        $perColumn = [];
        foreach ($columns as $col) {
            // Columns marked exempt_for_conductors (e.g. the exam) don't apply
            // to the 8 conductors themselves, so they're removed from both
            // numerator and denominator. Other columns count all participants.
            $pool = ! empty($col['exempt_for_conductors'])
                ? $participants->where('is_exam_conductor', false)
                : $participants;
            $poolTotal = $pool->count();
            $done = $pool->whereNotNull($col['field'])->count();
            $perColumn[$col['key']] = [
                'done'  => $done,
                'total' => $poolTotal,
                'pct'   => $poolTotal > 0 ? round($done * 100 / $poolTotal) : 0,
            ];
        }

        $squads = [];
        foreach ($participants->groupBy('squad_num') as $num => $rows) {
            $squadCols = [];
            foreach ($columns as $col) {
                $pool = ! empty($col['exempt_for_conductors'])
                    ? $rows->where('is_exam_conductor', false)
                    : $rows;
                $squadCols[$col['key']] = [
                    'done'  => $pool->whereNotNull($col['field'])->count(),
                    'total' => $pool->count(),
                ];
            }
            $squads[$num] = $squadCols;
        }

        return [
            'people'     => $total,
            'per_column' => $perColumn,
            'per_squad'  => $squads,
        ];
    }

    /** Admin matrix — CEO only. */
    public function admin(Request $request): View
    {
        $this->requireCeo($request);

        $participants = AiFirstParticipant::orderBy('squad_num')
            ->orderByRaw("FIELD(role_in_squad,'mentor','associate','mentee')")
            ->orderBy('name')
            ->get()
            ->groupBy('squad_num');

        $totals = [
            'people'    => AiFirstParticipant::count(),
            'activated' => AiFirstParticipant::whereNotNull('claude_activated_at')->count(),
        ];

        // Per-squad activation %.
        $bySquad = [];
        foreach ($participants as $squadNum => $rows) {
            $total = $rows->count();
            $done  = $rows->whereNotNull('claude_activated_at')->count();
            $bySquad[$squadNum] = [
                'mentor' => $rows->firstWhere('role_in_squad', 'mentor')?->name ?? '—',
                'total'  => $total,
                'done'   => $done,
                'pct'    => $total > 0 ? round($done * 100 / $total) : 0,
            ];
        }

        return view('ai-first.admin', compact('participants', 'totals', 'bySquad'));
    }

    /** Move a participant to a different squad / role. */
    public function adminMove(Request $request): RedirectResponse
    {
        $this->requireCeo($request);

        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:ai_first_participants,id',
            'new_squad'      => 'required|integer|min:1|max:9',
            'new_role'       => 'required|in:mentor,associate,mentee',
        ]);

        $p = AiFirstParticipant::findOrFail($validated['participant_id']);

        // Prevent duplicate (squad,name) which the unique index would block.
        $clash = AiFirstParticipant::where('squad_num', $validated['new_squad'])
            ->where('name', $p->name)
            ->where('id', '!=', $p->id)
            ->exists();
        if ($clash) {
            return redirect()->route('ai-first.admin')->withErrors(['move' => "{$p->name} is already in squad {$validated['new_squad']}"]);
        }

        $p->update([
            'squad_num'     => $validated['new_squad'],
            'role_in_squad' => $validated['new_role'],
        ]);

        return redirect()->route('ai-first.admin')->with('saved', "Moved {$p->name} to Squad {$validated['new_squad']} as {$validated['new_role']}");
    }

    /** Public view-only exam status page. Shows the 38 mentees with
     *  pass/pending state and (when assigned) which conductor will examine them. */
    public function exam()
    {
        $mentees = AiFirstParticipant::where('is_exam_conductor', false)
            ->orderBy('squad_num')
            ->orderBy('name')
            ->get();

        $conductors = AiFirstParticipant::where('is_exam_conductor', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $squadMentors = AiFirstParticipant::where('role_in_squad', 'mentor')
            ->get()
            ->mapWithKeys(fn ($m) => [$m->squad_num => $m->name]);

        // Count of mentees per conductor (assigned + unassigned).
        $perConductor = $mentees->groupBy('assigned_conductor')->map->count()->all();
        $unassignedCount = $mentees->whereNull('assigned_conductor')->count();

        $passed = $mentees->whereNotNull('exam_passed_at')->count();
        $total  = $mentees->count();

        // Role-matched question sets, keyed by participant name.
        $questionSets = config('ai_first_questions', []);

        // Prepend a mandatory setup section to EVERY assessee: signing in to
        // Tessa and connecting the Tessa MCP server are the two non-negotiable
        // skills. We also strip any duplicate sign-in / MCP-connect prompts the
        // person already had in their role set so nothing appears twice.
        $questionSets = $this->withMandatorySetup($questionSets);

        // Assessor name → job role, so the card can show who is assessing
        // (name + role) instead of just the bare name.
        $assessorRoles = [
            'Fida'          => 'Lead AI Engineer',
            'Sneha Prathap' => 'Gen AI Developer',
            'Yuvanesh'      => 'Tech Lead',
            'Perumal'       => 'Full Stack Developer',
            'Bhoomika'      => 'AI Engineer',
            'Saran'         => 'Data Analyst',
            'Akshara'       => 'HR',
        ];

        // Compute the 30-minute assessment slots per assessor, skipping the
        // 1:30-2:30 PM lunch hour. Each assessor starts at 11:00 AM and
        // works through their own queue in parallel with the others.
        $slots = $this->computeAssessmentSlots($mentees);

        return response()
            ->view('ai-first.exam', compact('mentees', 'conductors', 'squadMentors', 'passed', 'total', 'perConductor', 'unassignedCount', 'questionSets', 'slots', 'assessorRoles'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Build a fixed 30-min slot per assessee.
     *
     *   - Start of day: 11:00 AM IST
     *   - Slot length : 30 minutes
     *   - Lunch break : 13:30 - 14:30 (skip; if a slot would overlap, push
     *                    it to start at 14:30)
     *   - Order       : per assessor, deterministic (squad asc, then name).
     *                    Unassigned mentees get no slot.
     *
     * Returns: [ participant_id => ['start' => '11:00 AM', 'end' => '11:20 AM'] ]
     */
    /**
     * Prepend a "Mandatory Setup" section (Tessa sign-in + connect Tessa MCP)
     * to every assessee's question set, and remove any duplicate sign-in /
     * MCP-connect prompts already present in their role sections.
     */
    private function withMandatorySetup(array $questionSets): array
    {
        $mandatory = [
            'title' => '',
            'prompts' => [
                ['tool' => 'tessa',  'text' => 'Sign in to Tessa through Claude right now — show me the confirmation on screen.'],
                ['tool' => 'claude', 'text' => 'Connect an MCP server to Claude — show me the connection succeed on screen.'],
            ],
        ];

        foreach ($questionSets as $name => $set) {
            foreach ($set['sections'] as $i => $section) {
                $section['prompts'] = array_values(array_filter($section['prompts'], function ($p) {
                    $t = strtolower($p['text']);
                    // Drop anything that duplicates the two mandatory items.
                    return ! str_contains($t, 'sign in') && ! str_contains($t, 'mcp server');
                }));
                $set['sections'][$i] = $section;
            }
            // Remove any sections left empty after filtering, then prepend mandatory.
            $set['sections'] = array_values(array_filter($set['sections'], fn ($s) => ! empty($s['prompts'])));
            array_unshift($set['sections'], $mandatory);
            $questionSets[$name] = $set;
        }

        return $questionSets;
    }

    private function computeAssessmentSlots($mentees): array
    {
        $slotMinutes = 30;
        $dayStart    = 11 * 60;      // 11:00 AM (minutes from midnight)
        $lunchStart  = 13 * 60 + 30; // 1:30 PM
        $lunchEnd    = 14 * 60 + 30; // 2:30 PM

        $slots = [];
        $byAssessor = $mentees->whereNotNull('assigned_conductor')->groupBy('assigned_conductor');

        foreach ($byAssessor as $queue) {
            $sorted = $queue->sortBy([['squad_num', 'asc'], ['name', 'asc']]);
            $cursor = $dayStart;
            foreach ($sorted as $m) {
                // If this slot would overlap lunch, jump to lunch end.
                if ($cursor < $lunchEnd && ($cursor + $slotMinutes) > $lunchStart) {
                    $cursor = $lunchEnd;
                }
                $end = $cursor + $slotMinutes;
                $slots[$m->id] = [
                    'start' => $this->formatSlotTime($cursor),
                    'end'   => $this->formatSlotTime($end),
                ];
                $cursor = $end;
            }
        }

        return $slots;
    }

    private function formatSlotTime(int $minutesFromMidnight): string
    {
        $h = intdiv($minutesFromMidnight, 60);
        $m = $minutesFromMidnight % 60;
        $period = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;
        return sprintf('%d:%02d %s', $h12, $m, $period);
    }

    /** Mark a mentee as passed/cleared. AJAX endpoint for the exam view. */
    public function examMark(Request $request, int $participant)
    {
        $validated = $request->validate([
            'passed'    => 'required|boolean',
            'marked_by' => 'nullable|string|max:100',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $p = AiFirstParticipant::findOrFail($participant);

        if ($p->is_exam_conductor) {
            return response()->json(['ok' => false, 'error' => 'Conductors are exempt from the exam.'], 422);
        }

        if ($validated['passed']) {
            $p->exam_passed_at  = now();
            $p->exam_marked_by  = $validated['marked_by'] ?? null;
            $p->exam_notes      = $validated['notes'] ?? null;
        } else {
            $p->exam_passed_at  = null;
            $p->exam_marked_by  = null;
            $p->exam_notes      = null;
        }
        $p->save();

        $passed = AiFirstParticipant::where('is_exam_conductor', false)->whereNotNull('exam_passed_at')->count();
        $total  = AiFirstParticipant::where('is_exam_conductor', false)->count();

        return response()->json([
            'ok'           => true,
            'id'           => $p->id,
            'passed'       => $p->exam_passed_at !== null,
            'marked_by'    => $p->exam_marked_by,
            'marked_at'    => $p->exam_passed_at?->setTimezone('Asia/Kolkata')->format('d M, h:i A'),
            'notes'        => $p->exam_notes,
            'totals'       => ['passed' => $passed, 'total' => $total],
        ]);
    }

    private function requireCeo(Request $request): void
    {
        $user = $request->user();
        if (! $user || $user->role !== self::CEO_ROLE) {
            abort(403, 'CEO only');
        }
    }
}
