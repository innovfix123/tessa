<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFirstParticipant extends Model
{
    protected $table = 'ai_first_participants';

    protected $fillable = [
        'user_id',
        'name',
        'squad_num',
        'role_in_squad',
        'claude_activated_at',
        'claude_plan',
        'claude_notes',
        'tessa_mcp_connected_at',
        'slack_connected_at',
        'google_drive_connected_at',
        'google_calendar_connected_at',
        'gmail_connected_at',
        'is_exam_conductor',
        'exam_passed_at',
        'exam_marked_by',
        'exam_notes',
        'assigned_conductor',
    ];

    protected function casts(): array
    {
        return [
            'claude_activated_at'          => 'datetime',
            'tessa_mcp_connected_at'       => 'datetime',
            'slack_connected_at'           => 'datetime',
            'google_drive_connected_at'    => 'datetime',
            'google_calendar_connected_at' => 'datetime',
            'gmail_connected_at'           => 'datetime',
            'exam_passed_at'               => 'datetime',
            'is_exam_conductor'            => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActivated(): bool
    {
        return $this->claude_activated_at !== null;
    }

    public function isExamPassed(): bool
    {
        return $this->exam_passed_at !== null;
    }

    /** Mentor of this participant's squad (lookup helper). */
    public function squadMentor(): ?self
    {
        return self::where('squad_num', $this->squad_num)
            ->where('role_in_squad', 'mentor')
            ->first();
    }
}
