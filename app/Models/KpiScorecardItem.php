<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiScorecardItem extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'target',
        'weight',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weight'     => 'int',
            'sort_order' => 'int',
            'is_active'  => 'bool',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function weeklyReports(): HasMany
    {
        return $this->hasMany(KpiWeeklyReport::class, 'kpi_item_id');
    }

    public function monthlySummaries(): HasMany
    {
        return $this->hasMany(KpiMonthlySummary::class, 'kpi_item_id');
    }

    /**
     * Is this user allowed to appear in the KPI Report feature at all?
     * All active users EXCEPT config('kpi_report.excluded_roles') (technical
     * support) — except config('kpi_report.role_exception_user_ids') (Deeksha)
     * — and never config('kpi_report.excluded_user_ids').
     */
    public static function isEligibleSubject(User $u): bool
    {
        if (! $u->is_active) {
            return false;
        }
        $excludedIds = array_map('intval', (array) config('kpi_report.excluded_user_ids', []));
        if (in_array((int) $u->id, $excludedIds, true)) {
            return false;
        }
        $excludedRoles = (array) config('kpi_report.excluded_roles', []);
        $exceptionIds  = array_map('intval', (array) config('kpi_report.role_exception_user_ids', []));
        if (in_array($u->role, $excludedRoles, true) && ! in_array((int) $u->id, $exceptionIds, true)) {
            return false;
        }
        return true;
    }

    /**
     * Who fills a subject's KPI report: their own reporting_manager_id, unless
     * config('kpi_report.filler_overrides')[subject] reroutes it (e.g. JP fills
     * NULL-manager leadership: 2=>1, 3=>1, 4=>1). Null if no manager + no override.
     */
    public static function fillerIdFor(User $subject): ?int
    {
        $overrides = (array) config('kpi_report.filler_overrides', []);
        if (array_key_exists($subject->id, $overrides)) {
            return (int) $overrides[$subject->id];
        }
        return $subject->reporting_manager_id ? (int) $subject->reporting_manager_id : null;
    }

    /**
     * Active, eligible subjects whose KPI report THIS manager fills (filler ==
     * $manager) and who have at least one active KPI item. When $weekKey (a
     * Friday Y-m-d) is given, the same new-hire grace filters as
     * ManagerWorkReview apply so a just-joined hire isn't expected that week.
     */
    public static function fillableSubjectsFor(User $manager, ?string $weekKey = null): Collection
    {
        $overrides    = (array) config('kpi_report.filler_overrides', []);
        $assignedToMe = array_keys(array_filter($overrides, fn ($m) => (int) $m === (int) $manager->id));
        $assignedAway = array_keys(array_filter($overrides, fn ($m) => (int) $m !== (int) $manager->id));

        $subs = User::where('is_active', true)
            ->with('roleRelation:id,slug,name')
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
            })
            ->orderBy('name')
            ->get();

        // KPI eligibility + must have active KPI items.
        $withItems = self::where('is_active', true)
            ->whereIn('user_id', $subs->pluck('id'))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $subs = $subs->filter(fn (User $u) => self::isEligibleSubject($u)
            && in_array((int) $u->id, $withItems, true));

        if ($weekKey === null) {
            return $subs->values();
        }

        $weekStart = Carbon::parse($weekKey)->startOfWeek(Carbon::MONDAY);
        $weekEnd   = $weekStart->copy()->addDays(6)->endOfDay();
        $effectiveOverrides = (array) config('kra_effective_from', []);

        return $subs->filter(function (User $sub) use ($weekStart, $weekEnd, $effectiveOverrides) {
            $effectiveFrom = $effectiveOverrides[$sub->id] ?? null;
            if ($effectiveFrom) {
                if (Carbon::parse($effectiveFrom)->gt($weekStart)) {
                    return false;
                }
            } elseif ($sub->joining_date && $sub->joining_date->gte($weekStart)) {
                return false;
            }
            if ($sub->created_at && Carbon::parse($sub->created_at)->gt($weekEnd)) {
                return false;
            }
            return true;
        })->values();
    }
}
