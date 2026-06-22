<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One creator verdict on a reworked deliverable in the video handoff pipeline.
 * See migration 2026_06_02_150000_create_video_handoff_reviews_table for the
 * model rationale (append-only log, keyed on the raw video, derived state).
 */
class VideoHandoffReview extends Model
{
    protected $fillable = [
        'raw_upload_id',
        'creator_id',
        'verdict',
        'feedback',
        'report_date',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
        ];
    }

    public function rawUpload(): BelongsTo
    {
        return $this->belongsTo(CreativeUpload::class, 'raw_upload_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
