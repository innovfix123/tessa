<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogEntry extends Model
{
    public const CATEGORY_NOTE = 'note';

    public const CATEGORY_DECISION = 'decision';

    public const CATEGORY_PROBLEM = 'problem';

    public const CATEGORY_IDEA = 'idea';

    public const CATEGORY_MEETING = 'meeting';

    public const CATEGORIES = [
        self::CATEGORY_NOTE,
        self::CATEGORY_DECISION,
        self::CATEGORY_PROBLEM,
        self::CATEGORY_IDEA,
        self::CATEGORY_MEETING,
    ];

    public const SOURCE_TEXT = 'text';

    public const SOURCE_VOICE = 'voice';

    public const SOURCE_SLACK = 'slack';

    protected $fillable = [
        'user_id',
        'content',
        'category',
        'source',
        'slack_ts',
        'slack_permalink',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }
}
