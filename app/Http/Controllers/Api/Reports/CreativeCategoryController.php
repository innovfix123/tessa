<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\TeamFocusNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily "creative category" / team work-focus note.
 *
 * Setters (config('creative_category.setter_user_ids') — Krishnan/Kishore) write
 * a short free-text focus line; their direct reports see it on the dashboard and
 * as a sign-in modal. The note carries forward until the setter changes it.
 */
class CreativeCategoryController extends Controller
{
    private function setterIds(): array
    {
        return array_map('intval', config('creative_category.setter_user_ids', []));
    }

    private function todayStr(): string
    {
        return Carbon::today('Asia/Kolkata')->format('Y-m-d');
    }

    private function weekStartStr(): string
    {
        return Carbon::today('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }

    private function payloadFor(?TeamFocusNote $note): array
    {
        if (! $note) {
            return [
                'category'  => null,
                'setOn'     => null,
                'scope'     => 'day',
                'isToday'   => false,
                'isCurrent' => false,
            ];
        }

        $setOn   = $note->focus_date->format('Y-m-d');
        $scope   = $note->scope === 'week' ? 'week' : 'day';
        $isToday = $setOn === $this->todayStr();

        // Freshness: a day-note is "current" only on its own day; a week-note stays
        // current for the whole IST Mon–Sun week it was set in (lapses next Monday).
        $isCurrent = $scope === 'week' ? ($setOn >= $this->weekStartStr()) : $isToday;

        return [
            'category'  => $note->category,
            'setOn'     => $setOn,
            'scope'     => $scope,
            'isToday'   => $isToday,   // retained for back-compat (portal.js)
            'isCurrent' => $isCurrent,
        ];
    }

    /**
     * GET /api/creative-category
     * Returns up to two independent blocks (either may be null):
     *   - setter: this user posts a category for their own team (input box)
     *   - viewer: this user's own manager posts a category they should see
     * A manager who also reports to a setter (e.g. Kishore under Krishnan) gets
     * BOTH — they set their sub-team's category and still see their lead's.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $setters = $this->setterIds();

        $setter = in_array((int) $user->id, $setters, true)
            ? array_merge(['canEdit' => true], $this->payloadFor(TeamFocusNote::currentFor($user->id)))
            : null;

        $managerId = (int) ($user->reporting_manager_id ?? 0);
        $viewer = ($managerId && in_array($managerId, $setters, true))
            ? array_merge(['authorName' => User::find($managerId)?->name], $this->payloadFor(TeamFocusNote::currentFor($managerId)))
            : null;

        return response()->json([
            'ok'     => true,
            'setter' => $setter,
            'viewer' => $viewer,
        ]);
    }

    /**
     * POST /api/creative-category  { category: string, scope?: 'day'|'week' }
     * Setters only. Upserts today's row (carry-forward until changed); scope marks
     * the focus as just-today ('day', default) or the whole week ('week').
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array((int) $user->id, $this->setterIds(), true)) {
            return response()->json(['error' => 'You are not set up to post a team category.'], 403);
        }

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:500'],
            'scope'    => ['nullable', 'string', 'in:day,week'],
        ]);

        $note = TeamFocusNote::updateOrCreate(
            ['author_id' => $user->id, 'focus_date' => $this->todayStr()],
            [
                'category' => trim($validated['category']),
                'scope'    => $validated['scope'] ?? 'day',
            ]
        );

        return response()->json(array_merge(['ok' => true], $this->payloadFor($note)));
    }
}
