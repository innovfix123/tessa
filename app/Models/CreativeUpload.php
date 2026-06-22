<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreativeUpload extends Model
{
    protected $fillable = [
        'user_id',
        'field_key',
        'report_date',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'content',
        'folder_name',
        'handoff_seq',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'file_size' => 'integer',
            'handoff_seq' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Reworked versions of this raw video (video handoff pipeline). Only
     * meaningful for rows on field_key 'ai_videos_generated'.
     */
    public function handoffs(): HasMany
    {
        return $this->hasMany(VideoHandoff::class, 'raw_upload_id');
    }

    /**
     * Creator feedback verdicts on this raw video's reworks (video handoff
     * pipeline). Append-only; the latest one drives the derived review state.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(VideoHandoffReview::class, 'raw_upload_id');
    }
}
