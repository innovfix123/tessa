<?php

namespace App\Services;

use App\Models\CreativeUpload;
use App\Models\ManagerNotification;
use App\Models\User;
use App\Models\VideoHandoff;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single source of truth for the video handoff pipeline's people and its
 * dashboard notifications. Shared by CreativeUploadController (creator upload
 * hook), VideoHandoffController (Anaz rework), and the videos:nudge-pending
 * console command.
 *
 * Notifications land on the Content Lead's existing dashboard "Team updates"
 * panel (manager_notifications -> manager_id 20). Two distinct `source`
 * values keep the "submitted" and "reworked" lines for the same creator-day
 * from overwriting each other.
 */
class VideoHandoffNotifier
{
    /** Content Lead — receives the dashboard notifications. */
    public const KRISHNAN_USER_ID = 20;

    /** The video reworker. */
    public const ANAZ_USER_ID = 18;

    /** The creators' raw-video upload field. */
    public const RAW_FIELD = 'ai_videos_generated';

    /**
     * People inside Krishnan's reporting tree who are NOT raw-video creators and
     * must never appear in the handoff creator set:
     *   #18 Anaz   — the reworker/editor (also avoids self-scoping to an empty row).
     *   #19 Sooraj — graphic designer, doesn't feed the video pipeline.
     */
    private const NON_CREATOR_IDS = [self::ANAZ_USER_ID, 19];

    /**
     * Content creators feeding the pipeline — Krishnan's full active reporting
     * sub-tree (his direct reports AND their reports, e.g. Kishore's creators),
     * because raw videos are uploaded per-person under each creator's own
     * user_id, not rolled up through a sub-manager. Krishnan himself (the
     * root/recipient) is naturally excluded; NON_CREATOR_IDS (Anaz the editor,
     * Sooraj the graphic designer) are excluded explicitly — they report to
     * Krishnan but don't feed the raw-video pipeline. Inactive accounts (e.g.
     * former creators like Maanasi) drop out so they no longer appear in the
     * daily-report Video Handoffs section.
     */
    public static function creatorIds(): array
    {
        $collected = [];
        $frontier = [self::KRISHNAN_USER_ID];

        while (! empty($frontier)) {
            $children = User::whereIn('reporting_manager_id', $frontier)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
            // Only descend into ids we haven't seen — cycle-safe and terminating.
            $frontier = array_values(array_diff($children, $collected));
            $collected = array_merge($collected, $frontier);
        }

        return array_values(array_diff($collected, self::NON_CREATOR_IDS));
    }

    public static function isCreator(int $userId): bool
    {
        return in_array($userId, self::creatorIds(), true);
    }

    /**
     * Upsert (or clear) the "creator submitted raw videos" notification for
     * one creator + date. Called after a creator uploads or deletes a raw
     * video. Clearing on a zero count mirrors notifyManagerOfChoice().
     */
    public static function submissionNotice(int $creatorId, string $reportDate): void
    {
        $creator = User::find($creatorId);
        if (! $creator) {
            return;
        }

        $key = [
            'manager_id' => self::KRISHNAN_USER_ID,
            'team_member_id' => $creatorId,
            'source' => 'video_submitted',
            'source_ref' => $reportDate,
        ];

        $count = CreativeUpload::where('user_id', $creatorId)
            ->where('field_key', self::RAW_FIELD)
            ->where('report_date', $reportDate)
            ->whereNotNull('file_path')
            ->count();

        if ($count === 0) {
            ManagerNotification::where($key)->delete();
            return;
        }

        $noun = $count === 1 ? 'video' : 'videos';
        ManagerNotification::updateOrCreate($key, [
            'message' => "{$creator->name}: {$count} {$noun} submitted to Anaz",
            'dismissed_at' => null,
        ]);
    }

    /**
     * Upsert (or clear) the "Anaz reworked videos" notification for one
     * creator + date. Called after Anaz uploads or deletes an updated video.
     */
    public static function reworkNotice(int $creatorId, string $reportDate): void
    {
        $creator = User::find($creatorId);
        if (! $creator) {
            return;
        }

        $key = [
            'manager_id' => self::KRISHNAN_USER_ID,
            'team_member_id' => $creatorId,
            'source' => 'video_reworked',
            'source_ref' => $reportDate,
        ];

        $rawIds = CreativeUpload::where('user_id', $creatorId)
            ->where('field_key', self::RAW_FIELD)
            ->where('report_date', $reportDate)
            ->whereNotNull('file_path')
            ->pluck('id');

        $total = $rawIds->count();
        $updated = $total > 0
            ? VideoHandoff::whereIn('raw_upload_id', $rawIds)->distinct()->count('raw_upload_id')
            : 0;

        if ($updated === 0) {
            ManagerNotification::where($key)->delete();
            return;
        }

        ManagerNotification::updateOrCreate($key, [
            'message' => "Anaz updated {$updated}/{$total} of {$creator->name}'s videos",
            'dismissed_at' => null,
        ]);
    }

    /**
     * A content creator asked Anaz for changes on a reworked video. Surfaces
     * on Anaz's OWN dashboard "Tessa" tab (manager_notifications -> manager_id
     * 18) and pings him on Slack with the feedback so he can re-upload. Keyed
     * per raw video (source_ref = rawId) so approvedNotice() clears exactly
     * this row once the creator is finally happy.
     */
    public static function changesRequestedNotice(int $creatorId, int $rawId, string $feedback, string $reportDate): void
    {
        $creator = User::find($creatorId);
        if (! $creator) {
            return;
        }

        ManagerNotification::updateOrCreate([
            'manager_id' => self::ANAZ_USER_ID,
            'team_member_id' => $creatorId,
            'source' => 'video_changes_requested',
            'source_ref' => (string) $rawId,
        ], [
            'message' => "{$creator->name} requested changes on a reworked video: \""
                .Str::limit(trim($feedback), 140).'"',
            'dismissed_at' => null,
        ]);

        $anaz = User::find(self::ANAZ_USER_ID);
        if (! $anaz) {
            return;
        }

        $slack = new SlackService;
        $slackId = $anaz->slack_user_id ?: $slack->getUserIdByName($anaz->name);
        if (! $slackId) {
            Log::warning('VideoHandoffNotifier: could not resolve Anaz on Slack for changes-requested');

            return;
        }

        $quoted = '> '.str_replace("\n", "\n> ", trim($feedback));
        $msg = "*{$creator->name} requested changes on a reworked video*\n\n"
            .$quoted."\n\n"
            .'Open Tessa → Daily Reports → Video Handoffs to upload a corrected version.';

        // sendDirectMessage() respects the Slack quiet window on its own —
        // routine feedback, not urgent, so we don't bypass it.
        $slack->sendDirectMessage($slackId, $msg);
    }

    /**
     * The creator approved the rework — the loop is complete. Clear the pending
     * change-request notification on Anaz's dashboard. Silent by design (no DM).
     */
    public static function approvedNotice(int $creatorId, int $rawId, string $reportDate): void
    {
        ManagerNotification::where([
            'manager_id' => self::ANAZ_USER_ID,
            'team_member_id' => $creatorId,
            'source' => 'video_changes_requested',
            'source_ref' => (string) $rawId,
        ])->delete();
    }
}
