<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The creator-feedback side of the video handoff pipeline: each row is ONE
 * verdict a content creator gave on a reworked deliverable — either "approved"
 * (they're happy, loop complete) or "changes_requested" (with feedback text
 * Anaz acts on, then re-uploads). Append-only log: there is intentionally NO
 * unique on `raw_upload_id`, since one raw video collects several verdicts
 * across rework cycles until it's finally approved.
 *
 * Keyed on the raw video (`raw_upload_id` -> creative_uploads), NOT on a
 * specific `video_handoffs` row, so deleting/replacing a reworked file never
 * cascades away the feedback thread. The review STATE (awaiting / changes /
 * approved) is derived from the (handoffs, reviews) timeline in
 * VideoHandoffController::presentRaw(); the verdict here is the one durable
 * fact, being a human decision rather than something derivable from files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_handoff_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raw_upload_id');
            // The reviewing content creator (= creative_uploads.user_id).
            // users.id is a signed int, so the FK column must match.
            $table->integer('creator_id');
            $table->string('verdict', 20); // 'approved' | 'changes_requested'
            $table->text('feedback')->nullable();
            $table->date('report_date');
            $table->timestamps();

            $table->foreign('raw_upload_id')->references('id')->on('creative_uploads')->cascadeOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->cascadeOnDelete();
            // Timeline queries pull a raw's verdicts in order.
            $table->index(['raw_upload_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_handoff_reviews');
    }
};
