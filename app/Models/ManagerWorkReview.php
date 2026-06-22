<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerWorkReview extends Model
{
    /** We only track updated_at + submitted_at — no created_at column exists. */
    const CREATED_AT = null;

    protected $fillable = [
        'manager_id',
        'subordinate_id',
        'week_key',
        'rating_deliverables',
        'rating_quality',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'week_key'            => 'date',
            'rating_deliverables' => 'int',
            'rating_quality'      => 'int',
            'submitted_at'        => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function subordinate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subordinate_id');
    }

    /**
     * Active direct reports of $manager, excluding users whose role is in
     * config('review.exempt_roles') (default: coo/cmo/cfo).
     *
     * When $weekKey (a Friday in Y-m-d) is provided, two new-hire filters
     * kick in so retroactive reviews don't reappear after a manager has
     * already rated their team:
     *
     *   1. joining_date < Monday of rating week — a new hire is not rated
     *      for the same week they joined; their first review comes the week
     *      after they start. Ids in config('review.new_hire_grace_exempt_user_ids')
     *      bypass this and are reviewable in their joining week.
     *   2. created_at <= Sunday end-of-day of rating week — a user added to
     *      Tessa AFTER the rating window closed for that week (e.g. someone
     *      whose joining_date was backdated when their account was finally
     *      created) cannot retroactively trigger an "overdue" reminder for a
     *      week the manager already submitted.
     */
    /**
     * Whether the work-quality review for a given week (a Friday Y-m-d) has been
     * administratively waived for everyone via config('review.skip_weeks').
     * A waived week shows no overdue card, never blocks sign-off, and is not
     * nagged — see config/review.php.
     */
    public static function isSkippedWeek(?string $weekKey): bool
    {
        if (! $weekKey) {
            return false;
        }

        $normalized = Carbon::parse($weekKey)->format('Y-m-d');

        return in_array($normalized, (array) config('review.skip_weeks', []), true);
    }

    public static function rateableSubordinatesFor(User $manager, ?string $weekKey = null): Collection
    {
        $exempt = (array) config('review.exempt_roles', []);

        $overrides = (array) config('manager_ratings.rater_overrides', []);
        $assignedToMe = array_keys(array_filter($overrides, fn ($r) => (int) $r === (int) $manager->id));
        $assignedAway = array_keys(array_filter($overrides, fn ($r) => (int) $r !== (int) $manager->id));

        $query = User::where('is_active', true)
            ->where(function ($q) use ($manager, $assignedToMe, $assignedAway) {
                $q->where(function ($q2) use ($manager, $assignedAway) {
                    $q2->where('reporting_manager_id', $manager->id);
                    if ($assignedAway) {
                        $q2->whereNotIn('id', $assignedAway);
                    }
                });
                if ($assignedToMe) {
                    $q->orWhereIn('id', $assignedToMe);
                }
            });

        if (! empty($exempt)) {
            // User.role is a string accessor backed by roleRelation.slug — filter via join.
            $query->whereHas('roleRelation', fn ($q) => $q->whereNotIn('slug', $exempt));
        }

        $subs = $query->orderBy('name')->get();

        if ($weekKey === null) {
            return $subs;
        }

        $weekStart = Carbon::parse($weekKey)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        $effectiveOverrides = (array) config('kra_effective_from', []);
        $graceExempt = array_map('intval', (array) config('review.new_hire_grace_exempt_user_ids', []));

        return $subs->filter(function (User $sub) use ($weekStart, $weekEnd, $effectiveOverrides, $graceExempt) {
            // Per-user KRA effective-from override takes precedence over
            // joining_date so a hire whose first ~weeks are training can
            // be pinned to a later "start counting from" date without
            // mutating their HR joining_date (which letters/tenure use).
            $effectiveFrom = $effectiveOverrides[$sub->id] ?? null;
            if ($effectiveFrom) {
                $effectiveDate = Carbon::parse($effectiveFrom);
                if ($effectiveDate->gt($weekStart)) {
                    return false;
                }
            } elseif (
                ! in_array((int) $sub->id, $graceExempt, true)
                && $sub->joining_date && $sub->joining_date->gte($weekStart)
            ) {
                return false;
            }
            // User.$timestamps is false so created_at is a string, not Carbon.
            if ($sub->created_at && Carbon::parse($sub->created_at)->gt($weekEnd)) {
                return false;
            }
            return true;
        })->values();
    }
}
