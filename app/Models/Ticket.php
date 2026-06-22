<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    const CATEGORY_TECHNICAL = 'technical';

    const CATEGORY_AI = 'ai';

    const PRIORITY_LOW = 'low';

    const PRIORITY_MEDIUM = 'medium';

    const PRIORITY_HIGH = 'high';

    const STATUS_OPEN = 'open';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_RESOLVED = 'resolved';

    const STATUS_CLOSED = 'closed';

    const CATEGORIES = [self::CATEGORY_TECHNICAL, self::CATEGORY_AI];

    const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_MEDIUM, self::PRIORITY_HIGH];

    const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED];

    const CATEGORY_ASSIGNEE_MAP = [
        self::CATEGORY_TECHNICAL => 'yuvanesh@innovfix.in',
        self::CATEGORY_AI => 'fida@innovfix.in',
    ];

    protected $fillable = [
        'title',
        'description',
        'category',
        'priority',
        'status',
        'reporter_id',
        'assignee_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public static function resolveAssigneeId(string $category): ?int
    {
        $email = self::CATEGORY_ASSIGNEE_MAP[$category] ?? null;
        if (! $email) {
            return null;
        }

        return User::where('email', $email)->value('id');
    }
}
