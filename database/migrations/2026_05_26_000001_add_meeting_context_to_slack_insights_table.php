<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->string('meeting_id', 100)->nullable()->after('source_message_ts');
            $table->string('meeting_title', 255)->nullable()->after('meeting_id');
            $table->date('meeting_date')->nullable()->after('meeting_title');
            $table->integer('suggested_assignee_id')->nullable()->after('meeting_date');
            $table->enum('audience', ['personal', 'meeting'])->default('personal')->after('suggested_assignee_id');
            $table->json('audience_user_ids')->nullable()->after('audience');
            $table->string('source_note_hash', 64)->nullable()->after('audience_user_ids');
            $table->dateTime('snooze_until')->nullable()->after('status');

            $table->index('meeting_id');
            $table->index('audience');
            $table->index('source_note_hash');
            $table->index('snooze_until');
            $table->index(['user_id', 'status', 'snooze_until'], 'slack_insights_dashboard_idx');
        });

        // Make user_id nullable so shared (audience=meeting) rows can omit it.
        // The existing FK with cascadeOnDelete is preserved.
        DB::statement('ALTER TABLE slack_insights MODIFY COLUMN user_id INT NULL');
    }

    public function down(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->dropIndex('slack_insights_dashboard_idx');
            $table->dropIndex(['snooze_until']);
            $table->dropIndex(['source_note_hash']);
            $table->dropIndex(['audience']);
            $table->dropIndex(['meeting_id']);

            $table->dropColumn([
                'meeting_id',
                'meeting_title',
                'meeting_date',
                'suggested_assignee_id',
                'audience',
                'audience_user_ids',
                'source_note_hash',
                'snooze_until',
            ]);
        });

        DB::statement('ALTER TABLE slack_insights MODIFY COLUMN user_id INT NOT NULL');
    }
};
