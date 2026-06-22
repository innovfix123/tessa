<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySignin extends Model
{
    protected $fillable = [
        'user_id',
        'signin_date',
        'signed_in_at',
    ];

    protected function casts(): array
    {
        return [
            'signin_date' => 'date',
            'signed_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ensure a daily_signins row exists for the user on the given Kolkata calendar date (Y-m-d).
     *
     * @return array{signin: self, created: bool}
     */
    public static function ensureForKolkataDate(User $user, ?string $dateStr = null): array
    {
        $dateStr = $dateStr ?? Carbon::now('Asia/Kolkata')->format('Y-m-d');

        $created = false;
        $signin = self::firstOrCreate(
            ['user_id' => $user->id, 'signin_date' => $dateStr],
            ['signed_in_at' => now()],
        );
        $created = $signin->wasRecentlyCreated;

        return ['signin' => $signin, 'created' => $created];
    }

    /**
     * Punctuality indicator for HR/CEO sign-in views (IST).
     *
     * @return 'outline'|'yellow'|'green'|'red'|'gray'
     */
    public static function signinIndicator(?Carbon $signedInAt, string $status, Carbon $now, string $dateStr): string
    {
        if (in_array($status, ['on_leave', 'holiday'], true)) {
            return 'gray';
        }

        $todayStr = $now->format('Y-m-d');
        $isToday = $dateStr === $todayStr;

        if ($signedInAt !== null) {
            return 'green';
        }

        if (! $isToday) {
            return $dateStr < $todayStr ? 'red' : 'outline';
        }

        $startWindow = Carbon::parse($dateStr, 'Asia/Kolkata')->setTime(10, 0);
        $deadline = Carbon::parse($dateStr, 'Asia/Kolkata')->setTime(10, 30);

        if ($now->lt($startWindow)) {
            return 'outline';
        }

        if ($now->lt($deadline)) {
            return 'yellow';
        }

        return 'red';
    }

    /** Signed in after 10:30 AM IST on the given calendar date. */
    public static function signinDelayed(?Carbon $signedInAt, string $dateStr): bool
    {
        if ($signedInAt === null) {
            return false;
        }

        $signInIst = $signedInAt->copy()->setTimezone('Asia/Kolkata');
        $deadline = Carbon::parse($dateStr, 'Asia/Kolkata')->setTime(10, 30);

        return $signInIst->gt($deadline);
    }
}
