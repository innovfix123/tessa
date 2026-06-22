<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add assignment-intent columns so the dashboard surfaces a card ONLY to
     * the person responsible for actually doing the work — not to everyone
     * whose name was mentioned in the huddle notes.
     *
     *  - assigned_by_user_id : who assigned/instructed the task (FK to users)
     *  - confidence_score    : 0.00–1.00; AI's certainty that this is a real
     *                          action item with a clearly responsible owner
     *  - source_action_item  : verbatim line from Slack's AI Notes that the
     *                          insight was extracted from (for auditability)
     */
    public function up(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->integer('assigned_by_user_id')->nullable()->after('suggested_assignee_id');
            $table->decimal('confidence_score', 3, 2)->nullable()->after('priority');
            $table->text('source_action_item')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->dropColumn(['assigned_by_user_id', 'confidence_score', 'source_action_item']);
        });
    }
};
