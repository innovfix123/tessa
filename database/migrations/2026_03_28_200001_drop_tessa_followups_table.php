<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_chats', function (Blueprint $table) {
            if (Schema::hasColumn('tessa_chats', 'followup_id')) {
                $table->dropForeign(['followup_id']);
                $table->dropColumn('followup_id');
            }
        });

        Schema::dropIfExists('tessa_followups');
    }

    public function down(): void
    {
        Schema::create('tessa_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tessa_chat_id')->nullable()->constrained('tessa_chats')->nullOnDelete();
            $table->enum('type', ['custom', 'agenda_update', 'mom_update', 'daily_report', 'action_item'])->default('custom');
            $table->string('reference_key')->nullable();
            $table->string('reference_week_key')->nullable();
            $table->text('message');
            $table->string('share_token', 32)->nullable()->unique();
            $table->timestamp('deadline')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('slack_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tessa_chats', function (Blueprint $table) {
            $table->foreignId('followup_id')->nullable()->after('title')->constrained('tessa_followups')->nullOnDelete();
        });
    }
};
