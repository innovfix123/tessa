<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\JobDescription;
use App\Models\ManagerNotification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Shared candidate intake: store a résumé, create the candidate, best-effort AI
 * extraction, soft duplicate check, notify reviewers, audit log. Used by BOTH
 * the authenticated HiringController::uploadCandidate AND the public freelancer
 * open-portal upload (PublicRecruiterPortalController), so the logic lives once.
 */
class CandidateIntakeService
{
    public function __construct(
        private ResumeTextExtractor $resumeExtractor,
        private TessaAIService $tessaAI,
        private SlackService $slack
    ) {}

    /**
     * @return array{candidate: Candidate, duplicate_warning: ?string}
     */
    public function intake(
        JobDescription $jd,
        User $uploader,
        UploadedFile $resume,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null
    ): array {
        $dir = 'hiring/resumes/' . date('Y-m');
        $path = $resume->store($dir, 'public');
        if (! $path) {
            // store() returns false when the target dir isn't writable by the
            // PHP-FPM user (www-data) — usually a root-owned month folder. Log
            // the path so a permission regression is obvious, not silent.
            Log::error('Candidate résumé store() returned false', [
                'dir' => $dir,
                'disk' => 'public',
                'abs' => Storage::disk('public')->path($dir),
            ]);
            throw new \RuntimeException('Upload failed. Please try again.');
        }
        $ext = strtolower($resume->getClientOriginalExtension());

        $candidate = Candidate::create([
            'job_description_id' => $jd->id,
            'uploaded_by' => $uploader->id,
            'resume_path' => $path,
            'resume_name' => $resume->getClientOriginalName(),
            'resume_mime' => $resume->getClientMimeType(),
            'extracted_name' => trim((string) $name) ?: null,
            'extracted_email' => trim((string) $email) ?: null,
            'extracted_phone' => trim((string) $phone) ?: null,
            'extraction_status' => 'pending',
            'stage' => 'sourced',
        ]);

        // Best-effort AI extraction — NEVER blocks the upload.
        $this->extractInto($candidate, $path, $ext);

        // Soft duplicate check on this JD (same email/phone) — warn, don't block.
        $dupWarning = $this->duplicateWarning($candidate);

        $this->notifyReviewers($candidate->fresh(['jobDescription', 'uploader']), $jd);

        ActivityLogService::log(
            $uploader->id,
            'hiring.candidate_added',
            "Added a candidate for \"{$jd->title}\"",
            'candidate',
            $candidate->id
        );

        return [
            'candidate' => $candidate->fresh(['uploader', 'jobDescription:id,created_by,title']),
            'duplicate_warning' => $dupWarning,
        ];
    }

    private function extractInto(Candidate $candidate, string $path, string $ext): void
    {
        try {
            $abs = Storage::disk('public')->path($path);
            $text = $this->resumeExtractor->fromFile($abs, $ext);
            if ($text === '') {
                $candidate->update(['extraction_status' => 'failed']);
                return;
            }
            $ai = $this->tessaAI->extractResumeDetails($text);
            $found = $ai['name'] || $ai['email'] || $ai['phone'] || $ai['experience_years'] !== null || $ai['skills'];
            $candidate->update([
                // Keep any value the recruiter typed; otherwise use the AI's.
                'extracted_name' => $candidate->extracted_name ?: ($ai['name'] ?? null),
                'extracted_email' => $candidate->extracted_email ?: ($ai['email'] ?? null),
                'extracted_phone' => $candidate->extracted_phone ?: ($ai['phone'] ?? null),
                'experience_years' => $ai['experience_years'] ?? null,
                'skills' => $ai['skills'] ?? null,
                'extraction_status' => $found ? 'done' : 'failed',
            ]);
        } catch (\Throwable $e) {
            Log::warning('CandidateIntake: extraction failed', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);
            $candidate->update(['extraction_status' => 'failed']);
        }
    }

    private function duplicateWarning(Candidate $candidate): ?string
    {
        $email = $candidate->extracted_email;
        $phone = $candidate->extracted_phone;
        if (! $email && ! $phone) {
            return null;
        }
        $exists = Candidate::where('job_description_id', $candidate->job_description_id)
            ->where('id', '!=', $candidate->id)
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->orWhere('extracted_email', $email);
                }
                if ($phone) {
                    $q->orWhere('extracted_phone', $phone);
                }
            })
            ->exists();

        return $exists
            ? 'Heads up: a candidate with the same email/phone is already on this JD.'
            : null;
    }

    private function notifyReviewers(Candidate $candidate, JobDescription $jd): void
    {
        $recipientIds = array_values(array_unique(array_merge(
            array_map('intval', (array) config('hiring_access.jd_notify_user_ids', [])),
            [(int) $jd->created_by]
        )));
        $recipients = User::whereIn('id', $recipientIds)
            ->where('id', '!=', $candidate->uploaded_by)
            ->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $name = $candidate->extracted_name ?: 'A candidate';
        $by = $candidate->uploader->name ?? 'a recruiter';
        $url = rtrim((string) config('app.url'), '/') . '/#view=hiring';
        $slackMsg = "🧑‍💼 New candidate *{$name}* for *{$jd->title}* (from {$by}). Review in <{$url}|Hiring>.";
        $inApp = "New candidate \"{$name}\" for \"{$jd->title}\" is ready to review.";

        foreach ($recipients as $u) {
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $u->id,
                    'team_member_id' => (int) $candidate->uploaded_by,
                    'source' => 'hiring_candidate',
                    'source_ref' => (string) $candidate->id,
                ],
                ['message' => $inApp, 'dismissed_at' => null]
            );
            try {
                $slackId = $this->slack->getUserIdByName($u->name);
                if ($slackId) {
                    $this->slack->sendDirectMessage($slackId, $slackMsg);
                }
            } catch (\Throwable $e) {
                Log::warning('CandidateIntake: reviewer Slack DM failed', ['user' => $u->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
