<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Candidate;
use App\Models\CandidateInterview;
use App\Models\JobDescription;
use App\Models\ManagerNotification;
use App\Models\ProvisioningRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CandidateIntakeService;
use App\Services\CandidateStageService;
use App\Services\EmailIdGenerator;
use App\Services\OnboardingService;
use App\Services\ProvisioningService;
use App\Services\SlackService;
use App\Services\TessaAIService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Hiring / Recruitment (ATS) — Phase 1: Job Descriptions + recruiter assignment
 * (stages 1–2 of the pipeline).
 *
 * Three actors, scoped here (the sidebar flag in DashboardController is
 * convenience only — every action is re-checked):
 *   • HR / management (config hiring_access.roles) — see ALL JDs, assign them
 *     to freelance recruiters.
 *   • Panel members (JD authors + config hiring_access.panel_member_ids) —
 *     create JDs and see their own.
 *   • Freelance recruiters (role freelance_recruiter) — see only JDs assigned
 *     to them.
 */
class HiringController extends Controller
{
    public function __construct(
        private SlackService $slack,
        private CandidateIntakeService $intake,
        private TessaAIService $tessaAI,
        private CandidateStageService $stageService,
        private EmailIdGenerator $emailIdGenerator,
        private OnboardingService $onboardingService,
        private ProvisioningService $provisioning
    ) {}

    // ── Gating helpers ────────────────────────────────────────────────────────

    private function isHrManagement($user): bool
    {
        return in_array($user->role, (array) config('hiring_access.roles', []), true);
    }

    private function isFreelancer($user): bool
    {
        return $user->role === Role::SLUG_FREELANCE_RECRUITER;
    }

    private function canCreateJd($user): bool
    {
        if ($this->isHrManagement($user)) {
            return true;
        }
        if (in_array($user->id, (array) config('hiring_access.panel_member_ids', []), true)) {
            return true;
        }
        // Anyone who has already authored a JD stays a panel member.
        return JobDescription::where('created_by', $user->id)->exists();
    }

    private function canAccessHiring($user): bool
    {
        return $this->isHrManagement($user) || $this->isFreelancer($user) || $this->canCreateJd($user);
    }

    private function canViewJd($user, JobDescription $jd): bool
    {
        if ($this->isHrManagement($user)) {
            return true;
        }
        if ((int) $jd->created_by === (int) $user->id) {
            return true;
        }
        if ($this->isFreelancer($user)) {
            return $jd->recruiters()->where('users.id', $user->id)->exists();
        }
        return false;
    }

    private function freelanceRoleId(): ?int
    {
        return Role::where('slug', Role::SLUG_FREELANCE_RECRUITER)->value('id');
    }

    // ── Listing ───────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canAccessHiring($user)) {
            return response()->json(['error' => 'You are not part of the Hiring workflow.'], 403);
        }

        $query = JobDescription::with(['creator:id,name', 'recruiters:id,name'])
            ->orderByDesc('created_at');

        if ($this->isHrManagement($user)) {
            // sees all
        } elseif ($this->isFreelancer($user)) {
            $query->whereHas('recruiters', fn ($q) => $q->where('users.id', $user->id));
        } else {
            // panel member — only their own JDs
            $query->where('created_by', $user->id);
        }

        // Candidate counts: freelancers see their OWN count; HR/panel see total.
        if ($this->isFreelancer($user)) {
            $query->withCount(['candidates as candidate_count' => fn ($q) => $q->where('uploaded_by', $user->id)]);
        } else {
            $query->withCount(['candidates as candidate_count']);
        }

        $jds = $query->limit(200)->get()->map(fn ($jd) => $this->format($jd, $user));

        // The assign dropdown (HR only) lists active freelance recruiters.
        $freelancers = [];
        if ($this->isHrManagement($user) && ($roleId = $this->freelanceRoleId())) {
            $freelancers = User::where('role_id', $roleId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
                ->values();
        }

        return response()->json([
            'jds' => $jds,
            'freelancers' => $freelancers,
            'can_create' => $this->canCreateJd($user),
            'can_assign' => $this->isHrManagement($user),
            'is_freelancer' => $this->isFreelancer($user),
            'is_hr' => $this->isHrManagement($user),
            'user_id' => $user->id,
        ]);
    }

    public function showJd(JobDescription $jobDescription, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewJd($user, $jobDescription)) {
            return response()->json(['error' => 'You cannot view this job description.'], 403);
        }

        return response()->json([
            'jd' => $this->format($jobDescription->load(['creator:id,name', 'recruiters:id,name']), $user),
        ]);
    }

    // ── Create (stage 1) ──────────────────────────────────────────────────────

    public function storeJd(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canCreateJd($user)) {
            return response()->json(['error' => 'You are not allowed to create job descriptions.'], 403);
        }

        $sourceType = $request->input('source_type') === 'pdf' ? 'pdf' : 'form';

        $request->validate([
            'title' => 'required|string|max:200',
            'source_type' => 'required|in:form,pdf',
            // A template JD needs a description; a PDF JD needs the file.
            'description' => $sourceType === 'form' ? 'required|string|max:5000' : 'nullable|string|max:5000',
            'required_skills' => 'nullable|string|max:2000',
            'experience_level' => 'nullable|string|max:120',
            'salary_range' => 'nullable|string|max:120',
            'jd_file' => $sourceType === 'pdf'
                ? 'required|file|mimes:pdf|max:10240'
                : 'nullable|file|mimes:pdf|max:10240',
        ]);

        $filePath = null;
        $fileName = null;
        if ($request->hasFile('jd_file')) {
            $filePath = $request->file('jd_file')->store('hiring/jd/' . date('Y-m'), 'public');
            if (! $filePath) {
                return response()->json(['error' => 'Upload failed. Please try again.'], 500);
            }
            $fileName = $request->file('jd_file')->getClientOriginalName();
        }

        $jd = JobDescription::create([
            'created_by' => $user->id,
            'title' => trim((string) $request->input('title')),
            'description' => trim((string) $request->input('description')) ?: null,
            'required_skills' => trim((string) $request->input('required_skills')) ?: null,
            'experience_level' => trim((string) $request->input('experience_level')) ?: null,
            'salary_range' => trim((string) $request->input('salary_range')) ?: null,
            'source_type' => $sourceType,
            'jd_file_path' => $filePath,
            'jd_file_name' => $fileName,
            'status' => 'open',
        ]);

        ActivityLogService::log(
            $user->id,
            'hiring.jd_created',
            "Created job description: {$jd->title}",
            'job_description',
            $jd->id
        );

        $this->notifyHrOfNewJd($jd->fresh('creator'));

        return response()->json([
            'ok' => true,
            'message' => 'Job description saved. HR has been notified.',
            'jd' => $this->format($jd->load(['creator:id,name', 'recruiters:id,name']), $user),
        ], 201);
    }

    // ── Assign to freelancers (stage 2) ───────────────────────────────────────

    public function assignRecruiters(JobDescription $jobDescription, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can assign recruiters.'], 403);
        }

        $validated = $request->validate([
            'recruiter_ids' => 'required|array|min:1',
            'recruiter_ids.*' => 'integer',
        ]);

        // Keep only real, active freelance recruiters.
        $roleId = $this->freelanceRoleId();
        $ids = $roleId
            ? User::whereIn('id', $validated['recruiter_ids'])
                ->where('role_id', $roleId)
                ->where('is_active', true)
                ->pluck('id')
                ->all()
            : [];
        if (! $ids) {
            return response()->json(['error' => 'Pick at least one freelance recruiter.'], 422);
        }

        $existing = $jobDescription->recruiters()->pluck('users.id')->all();
        $newIds = array_values(array_diff($ids, $existing));
        $now = now();
        foreach ($newIds as $rid) {
            $jobDescription->recruiters()->attach($rid, [
                'assigned_by' => $user->id,
                'assigned_at' => $now,
            ]);
        }
        if ($jobDescription->status === 'open' || $jobDescription->status === 'draft') {
            $jobDescription->update(['status' => 'assigned']);
        }

        if ($newIds) {
            ActivityLogService::log(
                $user->id,
                'hiring.jd_assigned',
                "Assigned \"{$jobDescription->title}\" to " . count($newIds) . ' recruiter(s)',
                'job_description',
                $jobDescription->id
            );
            // No WhatsApp/notification — the JD simply appears in each recruiter's
            // open portal (their permanent /r/{token} link).
        }

        return response()->json([
            'ok' => true,
            'message' => $newIds
                ? "Assigned. It now appears in the recruiter's portal."
                : 'Those recruiters were already assigned.',
            'jd' => $this->format(
                $jobDescription->fresh(['creator:id,name', 'recruiters:id,name']),
                $user
            ),
        ]);
    }

    // ── Freelance recruiters: open-portal links + performance (HR) ─────────────

    /** Ensure a freelance recruiter has a permanent open-portal token; return it. */
    private function ensureRecruiterToken(User $u): string
    {
        if (! $u->recruiter_portal_token) {
            $u->update(['recruiter_portal_token' => Str::random(48)]);
        }
        return $u->recruiter_portal_token;
    }

    /** HR: list freelance recruiters with their permanent open-portal link + counts. */
    public function recruiters(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can view recruiters.'], 403);
        }
        $roleId = $this->freelanceRoleId();
        if (! $roleId) {
            return response()->json(['recruiters' => []]);
        }

        $base = rtrim((string) config('app.url'), '/');
        $recruiters = User::where('role_id', $roleId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'portal_link' => $base . '/r/' . $this->ensureRecruiterToken($u),
                'submitted' => Candidate::where('uploaded_by', $u->id)->count(),
                'selected' => Candidate::where('uploaded_by', $u->id)
                    ->whereIn('stage', Candidate::SELECTED_STAGES)->count(),
            ])
            ->values();

        return response()->json(['recruiters' => $recruiters]);
    }

    /** HR: rotate a recruiter's portal token — revokes the old link. */
    public function regenerateRecruiterLink(User $user, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isHrManagement($actor)) {
            return response()->json(['error' => 'Only HR can regenerate links.'], 403);
        }
        if ($user->role !== Role::SLUG_FREELANCE_RECRUITER) {
            return response()->json(['error' => 'Not a freelance recruiter.'], 422);
        }
        $user->update(['recruiter_portal_token' => Str::random(48)]);
        ActivityLogService::log($actor->id, 'hiring.recruiter_link_regenerated', "Regenerated portal link for {$user->name}", 'user', $user->id);

        return response()->json([
            'ok' => true,
            'portal_link' => rtrim((string) config('app.url'), '/') . '/r/' . $user->recruiter_portal_token,
        ]);
    }

    // ── Candidates (stages 3–4) ───────────────────────────────────────────────

    public function uploadCandidate(JobDescription $jobDescription, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isAssignedFreelancer($user, $jobDescription)) {
            return response()->json(['error' => 'Only a freelancer assigned to this JD can add candidates.'], 403);
        }

        $request->validate([
            'resume' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'name' => 'nullable|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:40',
        ]);

        // Store + create + AI-extract + dedup + notify reviewers (shared with the
        // public freelancer open-portal upload).
        $res = $this->intake->intake(
            $jobDescription,
            $user,
            $request->file('resume'),
            $request->input('name'),
            $request->input('email'),
            $request->input('phone')
        );

        return response()->json([
            'ok' => true,
            'message' => 'Candidate added. The hiring team has been notified.',
            'duplicate_warning' => $res['duplicate_warning'],
            'candidate' => $this->formatCandidate($res['candidate'], $user),
        ], 201);
    }

    public function candidates(JobDescription $jobDescription, Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Candidate::with(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest'])
            ->where('job_description_id', $jobDescription->id)
            ->orderByDesc('created_at');

        if ($this->isHrManagement($user) || (int) $jobDescription->created_by === (int) $user->id) {
            // HR or the panel owner sees all candidates on this JD.
        } elseif ($this->isAssignedFreelancer($user, $jobDescription)) {
            $query->where('uploaded_by', $user->id);
        } else {
            return response()->json(['error' => 'You cannot view candidates for this JD.'], 403);
        }

        $candidates = $query->limit(500)->get()->map(fn ($c) => $this->formatCandidate($c, $user));

        return response()->json([
            'candidates' => $candidates,
            'jd' => ['id' => $jobDescription->id, 'title' => $jobDescription->title],
        ]);
    }

    public function showCandidate(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canViewCandidate($user, $candidate)) {
            return response()->json(['error' => 'You cannot view this candidate.'], 403);
        }

        return response()->json([
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    public function reviewCandidate(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');
        if (! $this->canApproveCandidate($user, $candidate)) {
            return response()->json(['error' => 'Only the panel member or HR can review this candidate.'], 403);
        }

        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:1000',
        ]);

        try {
            if ($validated['action'] === 'approve') {
                $candidate = $this->stageService->transitionTo($candidate, 'tech_round', $user);
                $msg = 'Candidate approved — moved to the technical round.';
            } else {
                $candidate = $this->stageService->transitionTo($candidate, 'rejected', $user, ['reason' => $validated['reason'] ?? '']);
                $msg = 'Candidate rejected.';
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $msg,
            'candidate' => $this->formatCandidate($candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name']), $user),
        ]);
    }

    // ── Interviews (stages 5–6) ───────────────────────────────────────────────

    /** The interview round that's active for the candidate's current stage. */
    private function activeRound(Candidate $candidate): ?string
    {
        return match ($candidate->stage) {
            'tech_round' => 'technical',
            'hr_round' => 'hr',
            default => null,
        };
    }

    public function draftInterviewEmail(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');
        if (! $this->canApproveCandidate($user, $candidate)) {
            return response()->json(['error' => 'Only the panel member or HR can run interviews.'], 403);
        }

        $validated = $request->validate([
            'round' => 'required|in:technical,hr',
            'scheduled_at' => 'nullable|date',
            'meet_link' => 'nullable|string|max:500',
        ]);
        if ($validated['round'] !== $this->activeRound($candidate)) {
            return response()->json(['error' => 'That round is not active for this candidate right now.'], 422);
        }

        $dateLabel = $timeLabel = '';
        if (! empty($validated['scheduled_at'])) {
            $when = Carbon::parse($validated['scheduled_at']);
            $dateLabel = $when->format('D, d M Y');
            $timeLabel = $when->format('g:i A');
        }

        $email = $this->tessaAI->draftInterviewEmail([
            'candidate_name' => $candidate->extracted_name,
            'role_title' => $candidate->jobDescription->title ?? 'the role',
            'round' => $validated['round'],
            'date_label' => $dateLabel,
            'time_label' => $timeLabel,
            'meet_link' => $validated['meet_link'] ?? '',
            'company' => 'InnovFix',
        ]);

        // The technical-interview invite carries the actual JOB DESCRIPTION (a link to the
        // uploaded JD PDF and/or its text) so the candidate knows what they're interviewing
        // for. Appended deterministically (not model-generated) so it always matches the JD.
        $body = $email['body'];
        $jdBlock = null;
        if ($validated['round'] === 'technical') {
            $jdBlock = $this->jdSummaryForEmail($candidate);
            if ($jdBlock !== null) {
                $body = rtrim($body) . "\n\n" . $jdBlock;
            }
        }

        return response()->json(['ok' => true, 'subject' => $email['subject'], 'body' => $body, 'jd_block' => $jdBlock]);
    }

    /**
     * The JOB DESCRIPTION block appended to a technical-interview invite: the role title, a
     * link to the uploaded JD PDF (when present), and/or the JD text — so the candidate sees
     * exactly what the panel member posted for the role. Null when the JD has nothing beyond
     * the title to share (then no block is added).
     */
    private function jdSummaryForEmail(Candidate $candidate): ?string
    {
        $jd = $candidate->jobDescription;
        if (! $jd) {
            return null;
        }

        $hasFile = ! empty($jd->jd_file_path);
        $desc = trim((string) $jd->description);
        if (! $hasFile && $desc === '') {
            return null; // nothing beyond the title — skip the block entirely
        }

        $lines = ['JOB DESCRIPTION', $jd->title ?? 'the role'];
        if ($hasFile) {
            $lines[] = 'Job description: ' . asset('storage/' . $jd->jd_file_path);
        }
        if ($desc !== '') {
            $lines[] = '';
            $lines[] = $desc;
        }

        return implode("\n", $lines);
    }

    /**
     * Feature 9B: 1-hour interview slots (09:00–18:00 IST) for a date, marked
     * busy/free from the CALLER's own Google Calendar. Falls back to all-free
     * (calendar_connected=false → manual picking) when Google isn't connected.
     */
    public function calendarSlots(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => 'required|date']);
        $user = $request->user();

        $slots = [];
        for ($h = 9; $h < 18; $h++) {
            $slots[] = [
                'start' => sprintf('%02d:00', $h),
                'end' => sprintf('%02d:00', $h + 1),
                'label' => $this->slotLabel($h),
                'sm' => $h * 60,
                'em' => ($h + 1) * 60,
                'busy' => false,
            ];
        }

        $connected = false;
        if (! empty($user->google_access_token)) {
            try {
                $events = \App\Services\GoogleUserService::forUser($user)->getEventsForDate($validated['date']);
                $connected = true;
                foreach ($slots as &$slot) {
                    foreach ($events as $ev) {
                        $s = $ev['start_minutes'] ?? null;
                        $e = $ev['end_minutes'] ?? null;
                        if ($s === null || $e === null) {
                            continue; // skip all-day / undated events
                        }
                        if ($s < $slot['em'] && $e > $slot['sm']) { // overlap
                            $slot['busy'] = true;
                            break;
                        }
                    }
                }
                unset($slot);
            } catch (\Throwable $e) {
                $connected = false; // treat any calendar error as "not connected" → manual fallback
            }
        }

        return response()->json([
            'ok' => true,
            'calendar_connected' => $connected,
            'date' => $validated['date'],
            'slots' => array_map(fn ($s) => [
                'start' => $s['start'],
                'end' => $s['end'],
                'label' => $s['label'],
                'busy' => $s['busy'],
            ], $slots),
        ]);
    }

    private function slotLabel(int $h): string
    {
        $fmt = function (int $hour): string {
            $disp = $hour % 12;
            if ($disp === 0) {
                $disp = 12;
            }

            return $disp . ':00 ' . ($hour < 12 ? 'AM' : 'PM');
        };

        return $fmt($h) . ' – ' . $fmt($h + 1);
    }

    public function saveInterview(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');
        if (! $this->canApproveCandidate($user, $candidate)) {
            return response()->json(['error' => 'Only the panel member or HR can run interviews.'], 403);
        }

        $validated = $request->validate([
            'round' => 'required|in:technical,hr',
            'scheduled_at' => 'nullable|date',
            'meet_link' => 'nullable|string|max:500',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string|max:5000',
            'email_status' => 'nullable|in:draft,sent',
            'recording_link' => 'nullable|string|max:500',
            'feedback' => 'nullable|string|max:5000',
            'agenda' => 'nullable|string|max:5000',
            // Debounced auto-save (draft) from the interview modal — persist the
            // fields but skip the audit-log row so typing doesn't spam it.
            'silent' => 'nullable|boolean',
        ]);
        if ($validated['round'] !== $this->activeRound($candidate)) {
            return response()->json(['error' => 'That round is not active for this candidate right now.'], 422);
        }

        $interview = CandidateInterview::firstOrNew([
            'candidate_id' => $candidate->id,
            'round' => $validated['round'],
        ]);
        // Only touch the fields the client actually sent (supports partial saves).
        foreach (['scheduled_at', 'meet_link', 'email_subject', 'email_body', 'email_status', 'recording_link', 'feedback', 'agenda'] as $f) {
            if (! $request->has($f)) {
                continue;
            }
            $v = $request->input($f);
            if ($f === 'scheduled_at') {
                $interview->scheduled_at = $v ?: null;
            } elseif ($f === 'email_status') {
                $interview->email_status = $v ?: 'draft';
            } else {
                $interview->{$f} = is_string($v) ? (trim($v) ?: null) : $v;
            }
        }
        $interview->conducted_by = $user->id;
        $interview->save();

        if (! $request->boolean('silent')) {
            ActivityLogService::log(
                $user->id,
                'hiring.interview_saved',
                "Saved {$validated['round']} interview for candidate #{$candidate->id}",
                'candidate',
                $candidate->id
            );
        }

        return response()->json(['ok' => true, 'message' => 'Interview saved.', 'interview' => $this->formatInterview($interview)]);
    }

    public function setInterviewOutcome(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');
        if (! $this->canApproveCandidate($user, $candidate)) {
            return response()->json(['error' => 'Only the panel member or HR can run interviews.'], 403);
        }

        $validated = $request->validate([
            'round' => 'required|in:technical,hr',
            'outcome' => 'required|in:passed,failed',
            'feedback' => 'nullable|string|max:5000',
        ]);
        if ($validated['round'] !== $this->activeRound($candidate)) {
            return response()->json(['error' => 'That round is not active for this candidate right now.'], 422);
        }

        // Technical-round panel feedback is mandatory (min 50 chars) — it's the
        // written assessment HR relies on. The HR round may pass/fail without it.
        $feedback = trim((string) $request->input('feedback', ''));
        if ($validated['round'] === 'technical' && mb_strlen($feedback) < 50) {
            return response()->json(['error' => 'Panel feedback is required (at least 50 characters) for the technical round.'], 422);
        }

        $interview = CandidateInterview::firstOrNew(
            ['candidate_id' => $candidate->id, 'round' => $validated['round']]
        );

        // The Accept/Reject decision is only allowed AFTER the interview has happened —
        // mirrors the gated post-interview section in the modal. scheduled_at holds the IST
        // wall-clock the panel picked (app runs UTC, users are IST), so reinterpret it in IST
        // before comparing — otherwise the gate would lift ~5.5h late.
        $scheduled = $interview->scheduled_at
            ? Carbon::parse($interview->scheduled_at->format('Y-m-d H:i:s'), 'Asia/Kolkata')
            : null;
        if (! $scheduled || $scheduled->isFuture()) {
            return response()->json(['error' => 'You can record the decision only after the interview time.'], 422);
        }

        $interview->outcome = $validated['outcome'];
        $interview->conducted_by = $user->id;
        if ($feedback !== '') {
            $interview->feedback = $feedback;
        }
        $interview->save();

        ActivityLogService::log(
            $user->id,
            'hiring.interview_outcome',
            "Marked {$validated['round']} interview {$validated['outcome']} for candidate #{$candidate->id}" . ($feedback !== '' ? ' (with feedback)' : ''),
            'candidate',
            $candidate->id
        );

        try {
            if ($validated['round'] === 'technical') {
                $candidate = $validated['outcome'] === 'passed'
                    ? $this->stageService->transitionTo($candidate, 'hr_round', $user)
                    : $this->stageService->transitionTo($candidate, 'rejected', $user, ['reason' => 'Did not clear the technical round']);
            } elseif ($validated['outcome'] === 'failed') {
                $candidate = $this->stageService->transitionTo($candidate, 'rejected', $user, ['reason' => 'Did not clear the HR round']);
            }
            // hr + passed: candidate stays in hr_round; HR finishes with "Send to Tessa".
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Outcome saved.',
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews']),
                $user
            ),
        ]);
    }

    public function sendToTessa(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can send a candidate to Tessa.'], 403);
        }
        if ($candidate->stage !== 'hr_round') {
            return response()->json(['error' => 'The candidate must complete the HR round first.'], 422);
        }
        $hr = CandidateInterview::where('candidate_id', $candidate->id)->where('round', 'hr')->first();
        if (! $hr || $hr->outcome !== 'passed') {
            return response()->json(['error' => 'Mark the HR round as passed first.'], 422);
        }

        try {
            $candidate = $this->stageService->transitionTo($candidate, 'accepted', $user);
            $candidate = $this->startProvisioning($candidate, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        ActivityLogService::log(
            $user->id,
            'hiring.send_to_tessa',
            "Candidate #{$candidate->id} accepted — sent to Tessa ({$candidate->generated_email})",
            'candidate',
            $candidate->id
        );

        return response()->json([
            'ok' => true,
            'message' => "Accepted. Login id {$candidate->generated_email} generated; Fida + Yuvanesh notified to create the accounts.",
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    // ── Provisioning + Offer (stages 7–8) ─────────────────────────────────────

    /**
     * Generate the login id, open the provisioning ticket, ping Fida + Yuvanesh.
     * Pass $notify=false to defer the heads-up (the one-step addToTeam flow sends
     * its own once the account is actually created — see ProvisioningService).
     */
    private function startProvisioning(Candidate $candidate, User $actor, bool $notify = true): Candidate
    {
        $gen = $this->emailIdGenerator->generate($candidate->extracted_name ?: 'New Hire');
        $candidate->update(['generated_email' => $gen['email']]);

        $this->provisioning->openTicket($candidate->fresh(), $gen['strategy']);

        if ($notify) {
            $this->provisioning->notify($candidate->fresh());
        }

        return $this->stageService->transitionTo($candidate, 'provisioning', $actor);
    }

    public function markProvisioning(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'task' => 'nullable|in:tessa,workspace',
            'done' => 'required|boolean',
        ]);
        // Dashboard ticks omit the task — infer it from the viewer (Fida → tessa,
        // Yuvanesh → workspace). The Hiring modal still passes it explicitly.
        $task = $validated['task'] ?? null;
        if (! $task) {
            if ((int) $user->id === (int) config('hiring_access.tessa_provisioner_id')) {
                $task = 'tessa';
            } elseif ((int) $user->id === (int) config('hiring_access.workspace_provisioner_id')) {
                $task = 'workspace';
            }
        }
        if (! $task) {
            return response()->json(['error' => 'Specify which provisioning task to mark.'], 422);
        }
        if (! $this->canMarkProvisioning($user, $task)) {
            return response()->json(['error' => 'You are not assigned to this provisioning task.'], 403);
        }
        $pr = ProvisioningRequest::where('candidate_id', $candidate->id)->first();
        if (! $pr) {
            return response()->json(['error' => 'This candidate has no provisioning request yet.'], 422);
        }

        $col = $task === 'tessa' ? 'tessa_done_at' : 'workspace_done_at';
        $pr->{$col} = $validated['done'] ? now() : null;
        $done = ($pr->tessa_done_at ? 1 : 0) + ($pr->workspace_done_at ? 1 : 0);
        $pr->status = $done === 2 ? 'done' : ($done === 1 ? 'partial' : 'pending');
        $pr->save();

        // Feature 3: when a provisioner ticks their task done, clear their own
        // dashboard nudge for this candidate (scoped to that viewer only).
        if ($validated['done']) {
            ManagerNotification::where('manager_id', $user->id)
                ->where('source', 'hiring_provision')
                ->where('source_ref', (string) $candidate->id)
                ->update(['dismissed_at' => now()]);
        }

        ActivityLogService::log(
            $user->id,
            'hiring.provisioning_marked',
            ucfirst($task) . ' provisioning ' . ($validated['done'] ? 'done' : 'reopened') . " for candidate #{$candidate->id}",
            'candidate',
            $candidate->id
        );

        return response()->json([
            'ok' => true,
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    public function issueOffer(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can issue the offer letter.'], 403);
        }
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');

        // New flow: the probation/offer letter goes out straight after a passed HR
        // round — BEFORE any account provisioning. Walk the candidate forward to
        // `offer` quietly; Fida/Yuvanesh are pinged only once they accept and HR
        // adds them to the team (see EmployeeController candidate mode).
        if (! in_array($candidate->stage, ['hr_round', 'accepted', 'provisioning', 'offer'], true)) {
            return response()->json(['error' => 'Finish the HR round first, then issue the letter.'], 422);
        }
        if ($candidate->stage === 'hr_round') {
            $hr = CandidateInterview::where('candidate_id', $candidate->id)->where('round', 'hr')->first();
            if (! $hr || $hr->outcome !== 'passed') {
                return response()->json(['error' => 'Mark the HR round as passed first.'], 422);
            }
        }

        try {
            // Walk hr_round → accepted → provisioning → offer (forward-only edges),
            // with NO provisioning ticket/notification — that's deferred to acceptance.
            $next = ['hr_round' => 'accepted', 'accepted' => 'provisioning', 'provisioning' => 'offer'];
            while ($candidate->stage !== 'offer' && isset($next[$candidate->stage])) {
                $candidate = $this->stageService->transitionTo($candidate, $next[$candidate->stage], $user);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Reserve the @innovfix.in login id now so the Add-to-Team form can prefill
        // it — but no ProvisioningRequest and no Fida/Yuvanesh ping yet.
        if (! $candidate->generated_email) {
            $gen = $this->emailIdGenerator->generate($candidate->extracted_name ?: 'New Hire');
            $candidate->update(['generated_email' => $gen['email']]);
            $candidate = $candidate->fresh();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Opening the probation letter…',
            // Prefill keys match the Letters composer fields (commonFields()).
            // letter_type + employee_category land HR straight on the probation
            // (full-time) variant; they can switch the category in the composer.
            'prefill' => [
                'letter_type' => 'probation',
                'employee_category' => 'fulltime',
                'recipient_name' => $candidate->extracted_name,
                'recipient_email' => $candidate->extracted_email, // personal email — the letter goes here
                'recipient_phone' => $candidate->extracted_phone,
                'role_title' => $candidate->jobDescription->title ?? '',
            ],
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    /**
     * Flag a candidate's offer as accepted — by HR (manual fallback) or by the
     * Gmail auto-detector (Phase 2, via=auto). Account creation stays a human
     * step (the Team → Add Member form); this just unlocks that CTA.
     */
    public function markAccepted(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can mark an offer accepted.'], 403);
        }
        if ($candidate->stage !== 'offer') {
            return response()->json(['error' => 'Issue the letter first — only an issued offer can be accepted.'], 422);
        }

        if (! $candidate->offer_accepted_at) {
            $candidate->update(['offer_accepted_at' => now(), 'offer_accepted_via' => 'manual']);
            ActivityLogService::log(
                $user->id,
                'hiring.offer_accepted',
                "Candidate #{$candidate->id} offer marked accepted (manual)",
                'candidate',
                $candidate->id
            );
        }

        return response()->json([
            'ok' => true,
            'message' => 'Marked accepted — add them to the team.',
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    private function canMarkProvisioning($user, string $task): bool
    {
        if ($this->isHrManagement($user)) {
            return true;
        }
        $id = $task === 'tessa'
            ? (int) config('hiring_access.tessa_provisioner_id')
            : (int) config('hiring_access.workspace_provisioner_id');
        return (int) $user->id === $id;
    }

    // ── Onboarding (stage 9) ──────────────────────────────────────────────────

    public function onboardOptions(Request $request): JsonResponse
    {
        if (! $this->isHrManagement($request->user())) {
            return response()->json(['error' => 'Only HR can onboard a hire.'], 403);
        }
        return response()->json([
            'roles' => Role::orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values(),
            'managers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    /**
     * Build the users row for a hire from the validated onboarding form + the
     * candidate's generated email, and fire the new-joiner announcement (Feature 8).
     * Shared by onboardCandidate (multi-step) and addToTeam (one-step) — the caller
     * owns the hired_user_id link + stage transition.
     */
    private function createTessaAccount(Candidate $candidate, array $validated, User $actor): User
    {
        $joining = $validated['joining_date'] ?? now('Asia/Kolkata')->toDateString();
        $data = [
            'name' => $candidate->extracted_name ?: 'New Hire',
            'email' => $candidate->generated_email,
            'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
            'role_id' => $validated['role_id'],
            'reporting_manager_id' => $validated['reporting_manager_id'] ?? null,
            'is_active' => true,
            'employment_type' => $validated['employment_type'],
            'joining_date' => $joining,
            'designation' => trim((string) ($validated['designation'] ?? '')) ?: ($candidate->jobDescription->title ?? null),
            'personal_email' => $candidate->extracted_email,
            'personal_mobile' => $candidate->extracted_phone,
            'onboarding_required' => true,
        ];
        if ($validated['employment_type'] === 'internship') {
            $data['employee_status'] = 'intern';
            $data['internship_start_date'] = $joining;
            $data['intern_conversion_status'] = 'pending';
        } elseif ($validated['employment_type'] === 'freelancer') {
            $data['employee_status'] = 'active';
        } else {
            $data['employee_status'] = 'probation';
            $data['probation_start_date'] = $joining;
            $data['probation_end_date'] = Carbon::parse($joining)->addDays(30)->toDateString();
        }

        $newUser = User::create($data);

        // Feature 8: celebrate the new joiner company-wide. Never let it block the hire.
        try {
            Announcement::announceNewJoiner($newUser, $actor->id);
        } catch (\Throwable $e) {
            report($e);
        }

        // Provision their Employee Records entry (Drive folder + HR-sheet row) so they
        // appear immediately, before any document upload. Deferred + best-effort.
        app(\App\Services\HrGoogleSync::class)->provisionNewHire($newUser->id);

        return $newUser;
    }

    /**
     * Feature 3 — one-step "Add to Team Member". From a passed HR round, HR fills
     * role/manager/type/start-date and we: generate the login id, open the
     * provisioning ticket, auto-create the Tessa account, and notify Fida (verify
     * the auto-created login) + Yuvanesh (create Gmail+Slack) — each with a
     * dashboard "mark done" tick. Walks the proven forward edges in one request.
     */
    public function addToTeam(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can add a hire to the team.'], 403);
        }
        if ($candidate->stage !== 'hr_round') {
            return response()->json(['error' => 'The candidate must complete the HR round first.'], 422);
        }
        $hr = CandidateInterview::where('candidate_id', $candidate->id)->where('round', 'hr')->first();
        if (! $hr || $hr->outcome !== 'passed') {
            return response()->json(['error' => 'Mark the HR round as passed first.'], 422);
        }
        if ($candidate->hired_user_id) {
            return response()->json(['error' => 'This candidate already has a Tessa account.'], 422);
        }
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'employment_type' => 'required|in:full_time,internship,freelancer',
            'reporting_manager_id' => 'nullable|exists:users,id',
            'joining_date' => 'nullable|date',
            'designation' => 'nullable|string|max:120',
        ]);

        try {
            // hr_round → accepted → provisioning (generates login id + ticket; notify deferred).
            $candidate = $this->stageService->transitionTo($candidate, 'accepted', $user);
            $candidate = $this->startProvisioning($candidate, $user, false);

            if (User::where('email', $candidate->generated_email)->exists()) {
                return response()->json(['error' => "A user with {$candidate->generated_email} already exists."], 422);
            }

            // provisioning → offer → onboarding, creating the account in between.
            $candidate = $this->stageService->transitionTo($candidate, 'offer', $user);
            $newUser = $this->createTessaAccount($candidate, $validated, $user);
            $candidate->update(['hired_user_id' => $newUser->id]);
            $candidate = $this->stageService->transitionTo($candidate, 'onboarding', $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Now that the Tessa login exists, ping the provisioners (Fida verifies it; Yuvanesh creates Gmail+Slack).
        $this->provisioning->notify($candidate->fresh(), true);

        ActivityLogService::log(
            $user->id,
            'hiring.add_to_team',
            "Added candidate #{$candidate->id} to the team — Tessa account {$newUser->email} created",
            'candidate',
            $candidate->id
        );

        return response()->json([
            'ok' => true,
            'message' => "Tessa account created for {$newUser->email}. Fida + Yuvanesh notified.",
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    /** HR turns an accepted candidate into a real users row (the Tessa login). */
    public function onboardCandidate(Candidate $candidate, Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isHrManagement($user)) {
            return response()->json(['error' => 'Only HR can onboard a hire.'], 403);
        }
        $candidate->loadMissing('jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name');
        if (! in_array($candidate->stage, ['offer', 'provisioning'], true)) {
            return response()->json(['error' => 'Issue the offer first, then onboard the hire.'], 422);
        }
        if ($candidate->hired_user_id) {
            return response()->json(['error' => 'This candidate already has a Tessa account.'], 422);
        }
        if (! $candidate->generated_email) {
            return response()->json(['error' => 'No login id yet — send the candidate to Tessa first.'], 422);
        }

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'employment_type' => 'required|in:full_time,internship,freelancer',
            'reporting_manager_id' => 'nullable|exists:users,id',
            'joining_date' => 'nullable|date',
            'designation' => 'nullable|string|max:120',
        ]);

        if (User::where('email', $candidate->generated_email)->exists()) {
            return response()->json(['error' => "A user with {$candidate->generated_email} already exists."], 422);
        }

        try {
            $newUser = $this->createTessaAccount($candidate, $validated, $user);
            $candidate->update(['hired_user_id' => $newUser->id]);
            $candidate = $this->stageService->transitionTo($candidate, 'onboarding', $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        ActivityLogService::log(
            $user->id,
            'hiring.onboard',
            "Created Tessa account {$newUser->email} for candidate #{$candidate->id}",
            'candidate',
            $candidate->id
        );

        return response()->json([
            'ok' => true,
            'message' => "Tessa account created for {$newUser->email}. They can log in and finish onboarding.",
            'candidate' => $this->formatCandidate(
                $candidate->load(['uploader:id,name', 'jobDescription:id,created_by,title,description,required_skills,jd_file_path,jd_file_name', 'interviews', 'provisioningRequest']),
                $user
            ),
        ]);
    }

    // The new hire's own onboarding checklist + the "officially onboarded" flip.
    public function onboardingStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'required' => (bool) $user->onboarding_required,
            'status' => $this->onboardingService->status($user),
        ]);
    }

    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->onboarding_required) {
            return response()->json(['ok' => true, 'message' => 'You are already onboarded.']);
        }
        if (! $this->onboardingService->isComplete($user)) {
            return response()->json([
                'error' => 'Please fill all required fields and upload every mandatory document first.',
                'status' => $this->onboardingService->status($user),
            ], 422);
        }

        // Feature 4: completing the checklist marks the candidate hired and unlocks
        // Daily Reports, but the portal stays limited until probation ends — so we do
        // NOT clear onboarding_required here (the lock is gated on probation status).
        Candidate::where('hired_user_id', $user->id)->where('stage', 'onboarding')->get()
            ->each(function ($c) use ($user) {
                try {
                    $this->stageService->transitionTo($c, 'hired', $user);
                } catch (\Throwable $e) {
                    // best-effort
                }
            });

        ActivityLogService::log($user->id, 'hiring.onboarded', "{$user->name} completed onboarding", 'user', $user->id);

        return response()->json(['ok' => true, 'message' => '🎉 Profile complete! Daily Reports is now unlocked. Full portal access opens automatically when your probation ends.']);
    }

    // ── Candidate helpers ─────────────────────────────────────────────────────

    private function isAssignedFreelancer($user, JobDescription $jd): bool
    {
        return $this->isFreelancer($user)
            && $jd->recruiters()->where('users.id', $user->id)->exists();
    }

    private function canViewCandidate($user, Candidate $candidate): bool
    {
        if ($this->isHrManagement($user)) {
            return true;
        }
        $jd = $candidate->jobDescription;
        if ($jd && (int) $jd->created_by === (int) $user->id) {
            return true;
        }
        return $this->isFreelancer($user) && (int) $candidate->uploaded_by === (int) $user->id;
    }

    private function canApproveCandidate($user, Candidate $candidate): bool
    {
        if ($this->isHrManagement($user)) {
            return true;
        }
        $jd = $candidate->jobDescription;
        return $jd && (int) $jd->created_by === (int) $user->id;
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    private function notifyHrOfNewJd(JobDescription $jd): void
    {
        $recipients = User::whereIn('id', (array) config('hiring_access.jd_notify_user_ids', []))
            ->where('id', '!=', $jd->created_by) // don't notify the author about their own JD
            ->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $who = $jd->creator->name ?? 'A panel member';
        $url = rtrim((string) config('app.url'), '/') . '/#view=hiring';
        $slackMsg = "🧭 New job description: *{$jd->title}* from {$who}. "
            . "Assign it to a recruiter in <{$url}|Hiring>.";
        $inAppMsg = "New job description \"{$jd->title}\" needs a recruiter assigned.";

        foreach ($recipients as $u) {
            // In-app notification center (dedup on manager+source+ref).
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $u->id,
                    'team_member_id' => $jd->created_by,
                    'source' => 'hiring_jd',
                    'source_ref' => (string) $jd->id,
                ],
                ['message' => $inAppMsg, 'dismissed_at' => null]
            );

            // Slack DM (resolve-by-name, same as BillService).
            try {
                $slackId = $this->slack->getUserIdByName($u->name);
                if ($slackId) {
                    $this->slack->sendDirectMessage($slackId, $slackMsg);
                }
            } catch (\Throwable $e) {
                Log::warning('HiringController: HR Slack DM failed', [
                    'user' => $u->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Serializer ────────────────────────────────────────────────────────────

    private function format(JobDescription $jd, $user): array
    {
        return [
            'id' => $jd->id,
            'title' => $jd->title,
            'description' => $jd->description,
            'required_skills' => $jd->required_skills,
            'experience_level' => $jd->experience_level,
            'salary_range' => $jd->salary_range,
            'source_type' => $jd->source_type,
            'status' => $jd->status,
            'jd_file_url' => $jd->jd_file_path ? asset('storage/' . $jd->jd_file_path) : null,
            'jd_file_name' => $jd->jd_file_name,
            'creator' => $jd->relationLoaded('creator') && $jd->creator
                ? ['id' => $jd->creator->id, 'name' => $jd->creator->name]
                : null,
            'recruiters' => $jd->relationLoaded('recruiters')
                ? $jd->recruiters->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'assigned_at' => optional($r->pivot->assigned_at)->toIso8601String(),
                    'notified_at' => optional($r->pivot->notified_at)->toIso8601String(),
                ])->values()->all()
                : [],
            'can_manage' => $jd->canBeManagedBy($user),
            'can_assign' => $this->isHrManagement($user),
            'candidate_count' => (int) ($jd->candidate_count ?? 0),
            'can_upload' => $this->isFreelancer($user),
            'can_view_candidates' => $this->isHrManagement($user)
                || (int) $jd->created_by === (int) $user->id
                || $this->isFreelancer($user),
            'created_at' => $jd->created_at->toIso8601String(),
        ];
    }

    private function formatCandidate(Candidate $c, $user): array
    {
        return [
            'id' => $c->id,
            'job_description_id' => $c->job_description_id,
            // The candidate's job description — shown to the panel in the interview modal and
            // linked into the technical-interview invite (replaces the old free-text agenda).
            'jd' => ($c->relationLoaded('jobDescription') && $c->jobDescription) ? [
                'title' => $c->jobDescription->title,
                'description' => $c->jobDescription->description,
                'required_skills' => $c->jobDescription->required_skills,
                'jd_file_url' => $c->jobDescription->jd_file_path ? asset('storage/' . $c->jobDescription->jd_file_path) : null,
                'jd_file_name' => $c->jobDescription->jd_file_name,
            ] : null,
            'stage' => $c->stage,
            'extraction_status' => $c->extraction_status,
            'name' => $c->extracted_name,
            'email' => $c->extracted_email,
            'phone' => $c->extracted_phone,
            'experience_years' => $c->experience_years !== null ? (float) $c->experience_years : null,
            'skills' => $c->skills,
            'resume_url' => $c->resume_path ? asset('storage/' . $c->resume_path) : null,
            'resume_name' => $c->resume_name,
            'uploader' => $c->relationLoaded('uploader') && $c->uploader
                ? ['id' => $c->uploader->id, 'name' => $c->uploader->name]
                : null,
            'rejected_reason' => $c->rejected_reason,
            'interviews' => $c->relationLoaded('interviews')
                ? $c->interviews->map(fn ($i) => $this->formatInterview($i))->values()->all()
                : [],
            'provisioning' => ($c->relationLoaded('provisioningRequest') && $c->provisioningRequest)
                ? [
                    'generated_email' => $c->provisioningRequest->generated_email,
                    'tessa_done' => (bool) $c->provisioningRequest->tessa_done_at,
                    'workspace_done' => (bool) $c->provisioningRequest->workspace_done_at,
                    'status' => $c->provisioningRequest->status,
                    'can_mark_tessa' => $this->canMarkProvisioning($user, 'tessa'),
                    'can_mark_workspace' => $this->canMarkProvisioning($user, 'workspace'),
                ]
                : null,
            'can_approve' => $this->canApproveCandidate($user, $c),
            // Offer / acceptance (re-sequenced flow). `generated_email` is the
            // reserved @innovfix.in login id used to prefill the Add-to-Team form.
            'generated_email' => $c->generated_email,
            'offer_accepted' => (bool) $c->offer_accepted_at,
            'offer_accepted_at' => optional($c->offer_accepted_at)->toIso8601String(),
            'offer_accepted_via' => $c->offer_accepted_via,
            'hired_user_id' => $c->hired_user_id,
            'created_at' => $c->created_at->toIso8601String(),
        ];
    }

    private function formatInterview(CandidateInterview $i): array
    {
        return [
            'round' => $i->round,
            'scheduled_at' => optional($i->scheduled_at)->toIso8601String(),
            'meet_link' => $i->meet_link,
            'email_subject' => $i->email_subject,
            'email_body' => $i->email_body,
            'email_status' => $i->email_status,
            'recording_link' => $i->recording_link,
            'outcome' => $i->outcome,
            'feedback' => $i->feedback,
            'agenda' => $i->agenda,
        ];
    }
}
