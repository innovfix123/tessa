<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TessaTask extends Model
{
    /**
     * Statuses that represent active, in-flight work (pre-completion).
     * Health (on_track/at_risk/blocked) lives on `blocker_status` — keep it
     * out of `status` so picking "On Track" doesn't hide the task in a
     * health-named kanban column.
     */
    public const ACTIVE_STATUSES = ['pending', 'in_progress'];

    /** All valid status values for the `status` column. */
    public const ALL_STATUSES = ['pending', 'in_progress', 'completed', 'closed', 'cancelled', 'on_hold'];

    protected $fillable = [
        'assigned_by',
        'assigned_to',
        'shared_assigned_by',
        'title',
        'description',
        'priority',
        'status',
        'deadline',
        'reminded_at',
        'remind_count',
        'status_note',
        'ai_summary',
        'blocker_status',
        'blocker_note',
        'progress',
        'last_checkin_at',
        'next_checkin_at',
        'completed_at',
        'closed_at',
        'closed_by',
        'reopen_count',
        'reopen_reason',
        'original_deadline',
        'deadline_extension_count',
        'pending_extension_days',
        'extension_notice_days',
        // Mandatory completion gating
        'is_mandatory',
        'requires_attachment',
        'requires_form_url',
        'proof_submitted_at',
        'proof_note',
        // GitHub
        'github_branch',
        'github_pr_url',
        'github_pr_status',
        'github_repo',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'original_deadline' => 'datetime',
            'last_checkin_at' => 'datetime',
            'next_checkin_at' => 'datetime',
            'proof_submitted_at' => 'datetime',
            'is_mandatory' => 'boolean',
            'requires_attachment' => 'boolean',
        ];
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The "shared assigner" — the latest person who redirected this task on to
     * someone else (an assignee passing work down the line). NULL unless the
     * task has been redirected. Distinct from `assignedBy` (original creator).
     */
    public function sharedAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_assigned_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TaskMessage::class, 'task_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TaskParticipant::class, 'task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(TaskSubtask::class, 'task_id')->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'task_id');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(TaskBlocker::class, 'task_id')->orderByDesc('created_at');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(TaskCheckin::class, 'task_id')->orderByDesc('created_at');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id')
            ->withPivot('created_at');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id')
            ->withPivot('created_at');
    }

    public function isOverdue(): bool
    {
        return $this->deadline
            && ! $this->completed_at
            && ! in_array($this->status, ['cancelled', 'on_hold', 'closed'])
            && $this->deadline->isPast();
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isAwaitingVerification(): bool
    {
        return $this->status === 'completed';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isOnHold(): bool
    {
        return $this->status === 'on_hold';
    }

    /**
     * Reasons why a mandatory task cannot yet be marked complete.
     * Returns [] if requirements are met (or task is not mandatory).
     */
    public function completionGateErrors(int $assigneeId): array
    {
        if (! $this->is_mandatory) {
            return [];
        }

        $errors = [];

        if ($this->requires_attachment) {
            $hasUpload = $this->attachments()
                ->where('user_id', $assigneeId)
                ->exists();
            if (! $hasUpload) {
                $errors[] = 'Upload the required document before marking this task complete.';
            }
        }

        if (! empty($this->requires_form_url) && empty($this->proof_submitted_at)) {
            $errors[] = 'Confirm that you have filled the required form/sheet before marking this task complete.';
        }

        return $errors;
    }
}
