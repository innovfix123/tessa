<?php

namespace App\Services;

use App\Models\JobDescription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Thin abstraction over the freelance-recruiter notification channel. Today
 * that's WhatsApp (the recruiters are external); keeping the call sites behind
 * this wrapper means a future channel swap (or adding Slack/email fallback)
 * touches only this file, not HiringController.
 *
 * Bundled per recipient: one message per recruiter per assignment event.
 */
class RecruiterNotifier
{
    public function __construct(private WhatsAppService $whatsapp) {}

    /**
     * Tell the given recruiter user IDs that a JD was assigned to them, and
     * stamp the pivot's notified_at (recorded whether or not the send
     * succeeded — in dry-run we still want to know it was attempted).
     */
    public function notifyAssigned(JobDescription $jd, array $recruiterIds): void
    {
        $recruiterIds = array_values(array_unique(array_map('intval', $recruiterIds)));
        if (! $recruiterIds) {
            return;
        }

        $url = rtrim((string) (config('whatsapp.portal_url') ?: config('app.url')), '/') . '/#view=hiring';
        $template = (string) config('whatsapp.templates.jd_assigned', 'jd_assigned');

        $recruiters = User::whereIn('id', $recruiterIds)->get();
        foreach ($recruiters as $r) {
            $phone = trim((string) ($r->personal_mobile ?? ''));
            if ($phone === '') {
                Log::warning('RecruiterNotifier: recruiter has no personal_mobile', [
                    'recruiter_id' => $r->id,
                    'jd_id' => $jd->id,
                ]);
            } else {
                $this->whatsapp->sendTemplate($phone, $template, [$jd->title, $url]);
            }

            // Always stamp the attempt so the UI/queue reflects it.
            $jd->recruiters()->updateExistingPivot($r->id, ['notified_at' => now()]);
        }
    }
}
