<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\KraScorecardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KraScorecardController extends Controller
{
    /** User ids entirely excluded from the KRA system (JP, Bala, Nandha, Ayush). */
    private static function excludedKraUserIds(): array
    {
        return array_map('intval', (array) config('kra_exclusions.excluded_user_ids', []));
    }

    private static function isKraExcluded(int $userId): bool
    {
        return in_array($userId, self::excludedKraUserIds(), true);
    }

    public function show(Request $request, KraScorecardService $service): JsonResponse
    {
        $caller = $request->user();
        if (! $caller) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $isCeo = $caller->role === Role::SLUG_CEO;
        $targetId = (int) $request->query('user_id', 0);

        if ($isCeo) {
            if ($targetId <= 0) {
                return response()->json(['error' => 'user_id is required'], 422);
            }
            $target = User::find($targetId);
            if (! $target) {
                return response()->json(['error' => 'User not found'], 404);
            }
        } else {
            // Non-CEO users can only see their own scorecard
            $target = $caller;
        }

        if (self::isKraExcluded((int) $target->id)) {
            return response()->json(['ok' => true, 'excluded' => true, 'data' => null]);
        }

        $lastMonday = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->subWeek();
        $weekData = $service->buildWeek($target, $lastMonday->format('Y-m-d'));

        return response()->json([
            'ok'   => true,
            'data' => [
                'monthLabel'   => $lastMonday->format('M j') . ' – ' . $lastMonday->copy()->addDays(6)->format('M j, Y'),
                'monthAverage' => $weekData['composite'],
                'kras'         => $weekData['kras'],
                'weights'      => $weekData['weights'],
                'role'         => $weekData['role'],
            ],
        ]);
    }

    public function teamTable(Request $request, KraScorecardService $service): JsonResponse
    {
        $caller = $request->user();
        if (! $caller || $caller->role !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $monthStr = trim((string) $request->query('month', ''));
        if ($monthStr === '') {
            $monthStr = Carbon::now('Asia/Kolkata')->format('Y-m');
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
            return response()->json(['error' => 'month must be YYYY-MM'], 422);
        }

        [$year, $month] = array_map('intval', explode('-', $monthStr));
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Kolkata');
        $monthEnd = $monthStart->copy()->endOfMonth();
        $today = Carbon::now('Asia/Kolkata');

        $kraEpoch = Carbon::create(2026, 4, 13, 0, 0, 0, 'Asia/Kolkata');

        $weeks = [];
        $cursor = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        while ($cursor->lte($monthEnd)) {
            if ($cursor->lt($kraEpoch)) { $cursor->addWeek(); continue; }
            if ($cursor->gt($today)) break;
            $wEnd = $cursor->copy()->addDays(6);
            $nextMonday = $cursor->copy()->addWeek();
            $weeks[] = [
                'key'       => $cursor->format('Y-m-d'),
                'label'     => $cursor->format('M j').' – '.$wEnd->format('M j'),
                'published' => $today->gte($nextMonday),
            ];
            $cursor->addWeek();
        }

        $users = User::with('roleRelation:id,name,slug')
            ->where('is_active', true)
            ->whereNotIn('id', self::excludedKraUserIds())
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($users as $user) {
            $scores = [];
            foreach ($weeks as $w) {
                if ($w['published']) {
                    $wd = $service->buildWeek($user, $w['key']);
                    $scores[$w['key']] = $wd['composite'];
                } else {
                    $scores[$w['key']] = null;
                }
            }
            $rows[] = [
                'id'     => $user->id,
                'name'   => $user->name,
                'role'   => $user->roleRelation?->name ?? $user->role,
                'scores' => $scores,
            ];
        }

        return response()->json([
            'ok'        => true,
            'month'     => $monthStr,
            'monthLabel'=> $monthStart->format('F Y'),
            'weeks'     => $weeks,
            'employees' => $rows,
        ]);
    }

    public function history(Request $request, KraScorecardService $service): JsonResponse
    {
        $caller = $request->user();
        if (! $caller) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $isCeo = $caller->role === Role::SLUG_CEO;
        $targetId = (int) $request->query('user_id', 0);

        if ($isCeo) {
            if ($targetId <= 0) {
                return response()->json(['error' => 'user_id is required'], 422);
            }
            $target = User::find($targetId);
            if (! $target) {
                return response()->json(['error' => 'User not found'], 404);
            }
        } else {
            $target = $caller;
        }

        if (self::isKraExcluded((int) $target->id)) {
            return response()->json([
                'ok'       => true,
                'excluded' => true,
                'userName' => $target->name,
                'role'     => $target->role ?? 'default',
                'weeks'    => [],
            ]);
        }

        $weeks = max(1, min(26, (int) $request->query('weeks', 12)));
        $kraEpoch = Carbon::create(2026, 4, 13, 0, 0, 0, 'Asia/Kolkata');
        $thisWeekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);

        $cursor = $thisWeekStart->copy()->subWeek();
        $items = [];
        while ($cursor->gte($kraEpoch) && count($items) < $weeks) {
            $weekData = $service->buildWeek($target, $cursor->format('Y-m-d'));
            $items[] = [
                'weekKey'   => $cursor->format('Y-m-d'),
                'weekLabel' => $cursor->format('M j') . ' – ' . $cursor->copy()->addDays(6)->format('M j, Y'),
                'kras'      => $weekData['kras'],
                'composite' => $weekData['composite'],
            ];
            $cursor->subWeek();
        }

        return response()->json([
            'ok'      => true,
            'userName'=> $target->name,
            'role'    => $target->role ?? 'default',
            'weights' => $service->buildWeek($target, $thisWeekStart->copy()->subWeek()->format('Y-m-d'))['weights'],
            'weeks'   => $items,
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $caller = $request->user();
        if (! $caller) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if ($caller->role !== Role::SLUG_CEO) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $users = User::with('roleRelation:id,slug')
            ->where('is_active', true)
            ->whereNotIn('id', self::excludedKraUserIds())
            ->orderBy('name')
            ->get(['id', 'name', 'role_id'])
            ->map(fn (User $u) => [
                'id'   => $u->id,
                'name' => $u->name,
                'role' => $u->role ?? '',
            ]);

        return response()->json(['ok' => true, 'users' => $users]);
    }
}
