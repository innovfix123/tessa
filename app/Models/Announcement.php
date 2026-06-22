<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-wide dashboard announcement (Feature 8) — broadcast to every
 * employee, not scoped to a manager/owner. First use: a celebratory new-joiner
 * card. See the migration for the design rationale.
 */
class Announcement extends Model
{
    protected $fillable = [
        'type',
        'title',
        'body',
        'subject_user_id',
        'target_user_id',
        'created_by',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Not yet expired (null expiry = always active). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Create the "new team member joined" card for $user, visible for a week.
     * Safe to call from any account-creation path; callers should wrap in a
     * try/catch so a failure here never blocks the hire.
     */
    public static function announceNewJoiner(User $user, ?int $createdBy = null): self
    {
        $role = $user->designation ?: ($user->roleRelation?->name ?? null);
        $dept = $user->department?->name;
        $detail = implode(' · ', array_filter([$role, $dept]));

        return self::create([
            'type' => 'new_joiner',
            'title' => '🎉 New team member joined',
            'body' => trim($user->name . ($detail !== '' ? ' — ' . $detail : '')) . ' just joined the team. Say hello! 👋',
            'subject_user_id' => $user->id,
            'created_by' => $createdBy,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * PERSONAL "your travel expense is paid" card — visible only to the
     * submitter (target_user_id), posted by an admin from the Bills Records
     * tab. Distinct from the broadcast cards above (target_user_id is set).
     */
    public static function announceTravelPaid(Bill $bill, User $admin): self
    {
        $amount = '₹' . number_format((float) $bill->amount, 2);
        $body = 'Your travel expense of ' . $amount . ' has been paid'
            . ($bill->transaction_id ? ' (txn ' . $bill->transaction_id . ')' : '')
            . ' by ' . ($admin->name ?? 'Finance') . '.';

        return self::create([
            'type' => 'travel_paid',
            'title' => '✅ Travel expense paid',
            'body' => $body,
            'subject_user_id' => $bill->user_id,
            'target_user_id' => $bill->user_id,
            'created_by' => $admin->id,
            'expires_at' => now()->addDays(14),
        ]);
    }
}
