<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

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
        // Krishnan's direct reports get the sender choice on `ai_videos_generated`.
        // Krishnan himself is excluded — his row is the team aggregator, not a
        // per-person handoff.
        $teamIds = User::where('reporting_manager_id', self::KRISHNAN_USER_ID)->pluck('id');

        KpiDefinition::where('field_key', 'ai_videos_generated')
            ->whereIn('user_id', $teamIds)
            ->update(['choices' => self::SENDER_CHOICES]);

        // Anas gets the receiver choice on his `videos_delivered`. Sooraj has
        // the same field_key but is in a different team — leave him untouched.
        KpiDefinition::where('user_id', self::ANAS_USER_ID)
            ->where('field_key', 'videos_delivered')
            ->update(['choices' => self::RECEIVER_CHOICES]);
    }

    public function down(): void
    {
        $teamIds = User::where('reporting_manager_id', self::KRISHNAN_USER_ID)->pluck('id');

        KpiDefinition::where('field_key', 'ai_videos_generated')
            ->whereIn('user_id', $teamIds)
            ->update(['choices' => null]);

        KpiDefinition::where('user_id', self::ANAS_USER_ID)
            ->where('field_key', 'videos_delivered')
            ->update(['choices' => null]);
    }
};
