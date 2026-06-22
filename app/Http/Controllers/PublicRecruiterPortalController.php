<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\JobDescription;
use App\Models\Role;
use App\Models\User;
use App\Services\CandidateIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, passwordless "open portal" for a freelance recruiter, reached via their
 * one permanent link /r/{recruiter_portal_token}. No login. They view the JDs HR
 * assigned to them, upload résumés (several candidates at once), see their own
 * submission history and the live hiring stage of each candidate. Everything is
 * scoped to the token's recruiter; no other data is exposed.
 *
 * The page itself (show) is a thin shell that boots public/js/recruiter-portal.js;
 * all data flows over the token-scoped JSON endpoints below (jobs / job /
 * storeCandidate). Those POSTs are CSRF-exempt (see bootstrap/app.php) because the
 * 48-char unguessable token IS the credential — same trust model as letter share
 * links — which also means a long-open tab never hits a 419 "page expired".
 */
class PublicRecruiterPortalController extends Controller
{
    /**
     * Internal stage → what the freelancer sees. Single source of truth for the
     * label, badge class and 1-6 step (0 = terminal-negative, no progress bar).
     */
    private const STAGE_MAP = [
        'sourced'      => ['Submitted',       'pending', 1],
        'panel_review' => ['Panel Review',    'pending', 2],
        'tech_round'   => ['Technical Round', 'sel',     3],
        'hr_round'     => ['HR Round',        'sel',     4],
        'accepted'     => ['Offered',         'sel',     5],
        'provisioning' => ['Offered',         'sel',     5],
        'offer'        => ['Offered',         'sel',     5],
        'onboarding'   => ['Hired',           'hired',   6],
        'hired'        => ['Hired',           'hired',   6],
        'rejected'     => ['Rejected',        'neg',     0],
        'withdrawn'    => ['Withdrawn',       'neg',     0],
    ];

    public function __construct(private CandidateIntakeService $intake) {}

    /** The shell page. Renders the invalid view or boots the dashboard SPA. */
    public function show(string $token)
    {
        $user = $this->resolve($token);

        return response()
            ->view('hiring.recruiter-portal', [
                'invalid' => ! $user,
                'name'    => $user->name ?? null,
                'token'   => $token,
            ], $user ? 200 : 404)
            // No HTTP caching, so a redeployed JS/CSS shell shows up immediately.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /** GET /r/{token}/jobs — assigned (non-closed) JDs + this recruiter's counts. */
    public function jobs(string $token): JsonResponse
    {
        $user = $this->resolve($token);
        if (! $user) {
            return response()->json(['error' => 'invalid token'], 404);
        }

        $jds = JobDescription::whereHas('recruiters', fn ($q) => $q->where('users.id', $user->id))
            ->where('status', '!=', 'closed')
            ->orderByDesc('created_at')
            ->get();

        $counts = Candidate::where('uploaded_by', $user->id)
            ->selectRaw('job_description_id, count(*) c')
            ->groupBy('job_description_id')
            ->pluck('c', 'job_description_id');

        return response()->json([
            'recruiter' => ['name' => $user->name],
            'stats'     => [
                'submitted' => (int) Candidate::where('uploaded_by', $user->id)->count(),
                'selected'  => (int) Candidate::where('uploaded_by', $user->id)
                    ->whereIn('stage', Candidate::SELECTED_STAGES)->count(),
            ],
            'jobs' => $jds->map(fn (JobDescription $jd) => [
                'id'               => $jd->id,
                'title'            => $jd->title,
                'experience_level' => $jd->experience_level,
                'salary_range'     => $jd->salary_range,
                'required_skills'  => $jd->required_skills,
                'description'      => $jd->description,
                'jd_file_url'      => $jd->jd_file_path ? asset('storage/' . $jd->jd_file_path) : null,
                'submitted_count'  => (int) ($counts[$jd->id] ?? 0),
            ])->values(),
        ]);
    }

    /** GET /r/{token}/jobs/{jd} — full JD + this recruiter's candidates for it. */
    public function job(string $token, int $jd): JsonResponse
    {
        $user = $this->resolve($token);
        if (! $user) {
            return response()->json(['error' => 'invalid token'], 404);
        }

        $job = $this->assignedJob($user, $jd);
        if (! $job) {
            return response()->json(['error' => 'That job is no longer available to you.'], 404);
        }

        $candidates = Candidate::where('uploaded_by', $user->id)
            ->where('job_description_id', $job->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Candidate $c) => $this->candidateView($c));

        return response()->json([
            'jd' => [
                'id'               => $job->id,
                'title'            => $job->title,
                'experience_level' => $job->experience_level,
                'salary_range'     => $job->salary_range,
                'required_skills'  => $job->required_skills,
                'description'      => $job->description,
                'jd_file_url'      => $job->jd_file_path ? asset('storage/' . $job->jd_file_path) : null,
            ],
            'candidates' => $candidates->values(),
        ]);
    }

    /** POST /r/{token}/jobs/{jd}/candidates — one résumé per request (the SPA loops). */
    public function storeCandidate(string $token, int $jd, Request $request): JsonResponse
    {
        $user = $this->resolve($token);
        if (! $user) {
            return response()->json(['error' => 'invalid token'], 404);
        }

        $request->validate([
            'resume' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'name'   => 'nullable|string|max:150',
            'email'  => 'nullable|email|max:150',
            'phone'  => 'nullable|string|max:40',
        ]);

        $job = $this->assignedJob($user, $jd);
        if (! $job) {
            return response()->json(['error' => 'That job is no longer open to you.'], 422);
        }

        try {
            $result = $this->intake->intake(
                $job,
                $user,
                $request->file('resume'),
                $request->input('name'),
                $request->input('email'),
                $request->input('phone')
            );
        } catch (\Throwable $e) {
            Log::warning('recruiter portal candidate upload failed', [
                'recruiter' => $user->id,
                'jd'        => $job->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Upload failed. Please try again.'], 500);
        }

        return response()->json([
            'ok'                => true,
            'candidate'         => $this->candidateView($result['candidate']),
            'duplicate_warning' => $result['duplicate_warning'],
        ]);
    }

    /** The JD must be currently assigned to THIS recruiter and still open. */
    private function assignedJob(User $user, int $jdId): ?JobDescription
    {
        return JobDescription::where('id', $jdId)
            ->whereHas('recruiters', fn ($q) => $q->where('users.id', $user->id))
            ->where('status', '!=', 'closed')
            ->first();
    }

    private function resolve(string $token): ?User
    {
        $u = User::where('recruiter_portal_token', $token)->where('is_active', true)->first();
        if (! $u || $u->role !== Role::SLUG_FREELANCE_RECRUITER) {
            return null;
        }
        return $u;
    }

    /** Serialize one candidate for the portal, with the recruiter-facing stage. */
    private function candidateView(Candidate $c): array
    {
        [$label, $class, $step] = self::STAGE_MAP[$c->stage] ?? ['Submitted', 'pending', 1];

        return [
            'id'              => $c->id,
            'name'            => $c->extracted_name ?: null,
            'resume_name'     => $c->resume_name,
            'resume_url'      => $c->resume_path ? asset('storage/' . $c->resume_path) : null,
            'date'            => optional($c->created_at)->format('d M Y'),
            'stage'           => $c->stage,
            'stage_label'     => $label,
            'stage_class'     => $class,
            'step'            => $step,
            'rejected_reason' => $c->stage === 'rejected' ? ($c->rejected_reason ?: null) : null,
        ];
    }
}
