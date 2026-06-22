<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamFocusNote extends Model
{
    protected $fillable = [
        'author_id',
        'focus_date',
        'category',
        'scope',
    ];

    protected function casts(): array
    {
        return [
            'focus_date' => 'date',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The author's most recent note (carry-forward): what their team should see
     * until the author sets a newer one. Null if they've never set one.
     */
    public static function currentFor(int $authorId): ?self
    {
        return static::where('author_id', $authorId)
            ->orderByDesc('focus_date')
            ->orderByDesc('id')
            ->first();
    }
}
