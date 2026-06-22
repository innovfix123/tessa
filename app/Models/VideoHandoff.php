<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reworked video file uploaded by Anaz for a content creator's raw video.
 * See migration 2026_05_24_000019_create_video_handoffs_table for the model
 * rationale (lazy / derived status, inline file storage).
 */
class VideoHandoff extends Model
{
    protected $fillable = [
        'raw_upload_id',
        'updated_file_path',
        'updated_file_name',
        'updated_file_size',
        'updated_file_type',
        'ratio',
        'updated_by',
        'report_date',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'updated_file_size' => 'integer',
        ];
    }

    public function rawUpload(): BelongsTo
    {
        return $this->belongsTo(CreativeUpload::class, 'raw_upload_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
