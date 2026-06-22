<?php

use App\Models\KpiDefinition;
use App\Models\ManagerNotification;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Retires the old choice-tag video handoff flow (migrations 000015-000017),
 * superseded by the real file pipeline in `video_handoffs`.
 *
 *  - Anaz (#18) `videos_delivered` field is soft-deleted — the new per-creator
 *    pipeline section replaces it. Sooraj (#19) shares the `videos_delivered`
 *    field_key but is a separate team — he is intentionally untouched.
 *  - The "Sent to Anas / Not sent to Anas" choices on the content team's
 *    `ai_videos_generated` field are nulled (the exact inverse of 000017's
 *    up()), which makes the data-driven choice radio + chip disappear from
 *    creators' daily reports with no frontend change.
 *  - Stale `daily_report_choice` notifications on Krishnan's dashboard are
 *    cleared so they don't linger after their source choices are gone.
 */
return new class extends Migration
{
    private const KRISHNAN_USER_ID = 20;
    private const ANAS_USER_ID = 18;

    private const SENDER_CHOICES = [
        ['value' => 'sent_to_anas', 'label' => 'Sent to Anas'],
        ['value' => 'not_sent_to_anas', 'label' => 'Not sent to Anas'],
    ];

    private const RECEIVER_CHOICES = [
        ['value' => 'received_and_done', 'label' => 'Received and done'],
        ['value' => 'received_not_done', 'label' => 'Received & not done'],
        ['value' => 'not_received', 'label' => 'Not received'],
    ];

    public function up(): void
    {
        // Anaz's receiver field — soft-deleted, history preserved.
        KpiDefinition::where('user_id', self::ANAS_USER_ID)
            ->where('field_key', 'videos_delivered')
            ->delete();

        // Null the sender choices for Krishnan's content team.
        $teamIds = User::where('reporting_manager_id', self::KRISHNAN_USER_ID)->pluck('id');
        KpiDefinition::where('field_key', 'ai_videos_generated')
            ->whereIn('user_id', $teamIds)
            ->update(['choices' => null]);

        // Clear the old dashboard notifications produced by the choice flow.
        ManagerNotification::where('manager_id', self::KRISHNAN_USER_ID)
            ->where('source', 'daily_report_choice')
            ->delete();
    }

    public function down(): void
    {
        // Restore Anaz's field, then re-apply its receiver choices.
        KpiDefinition::withTrashed()
            ->where('user_id', self::ANAS_USER_ID)
            ->where('field_key', 'videos_delivered')
            ->restore();

        KpiDefinition::where('user_id', self::ANAS_USER_ID)
            ->where('field_key', 'videos_delivered')
            ->update(['choices' => self::RECEIVER_CHOICES]);

        // Restore the sender choices for the content team.
        $teamIds = User::where('reporting_manager_id', self::KRISHNAN_USER_ID)->pluck('id');
        KpiDefinition::where('field_key', 'ai_videos_generated')
            ->whereIn('user_id', $teamIds)
            ->update(['choices' => self::SENDER_CHOICES]);

        // The cleared notifications are not restored — they were transient.
    }
};
