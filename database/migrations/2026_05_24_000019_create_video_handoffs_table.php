<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the "update" side of the video handoff pipeline: each row is ONE
 * reworked video file uploaded by Anaz (#18) for a content creator's raw
 * video.
 *
 * A raw video is an existing `creative_uploads` row on field_key
 * 'ai_videos_generated'. There is intentionally NO unique constraint on
 * `raw_upload_id` — a single raw video may carry several updated versions,
 * and Anaz can delete/replace them individually. A raw video's status is
 * derived, not stored: it is "updated" (green) once it has >= 1 row here,
 * "pending" (red) otherwise.
 *
 * The reworked file is stored inline here (path on the `public` disk) rather
 * than as another `creative_uploads` row, so it never enters the daily-report
 * count roll-up in CreativeUploadController::syncManagerAggregateCount().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_handoffs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('raw_upload_id');
            $table->string('updated_file_path', 500);
            $table->string('updated_file_name', 255);
            $table->unsignedBigInteger('updated_file_size');
            $table->string('updated_file_type', 20);
            $table->integer('updated_by');
            $table->date('report_date');
            $table->timestamps();

            // creative_uploads is hard-deleted; cascade keeps handoff rows
            // from ever orphaning (the updated blob is cleaned up separately
            // in CreativeUploadController::handleDelete).
            $table->foreign('raw_upload_id')->references('id')->on('creative_uploads')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index('report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_handoffs');
    }
};
