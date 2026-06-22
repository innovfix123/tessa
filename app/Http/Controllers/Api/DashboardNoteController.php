<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardNote;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notes = DashboardNote::where('user_id', $request->user()->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (DashboardNote $n) => $this->format($n));

        return response()->json(['notes' => $notes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:5000',
            'items' => 'nullable|array',
            'items.*.text' => 'required_with:items|string|max:500',
            'items.*.checked' => 'boolean',
            'is_pinned' => 'boolean',
            'reminder_interval' => 'nullable|integer|in:10,15,30,45,60',
            'reminder_at' => 'nullable|date',
            'reminder_day' => 'nullable|integer|between:1,31',
        ]);

        [$interval, $remindAt, $day] = $this->resolveReminder($data);

        $note = DashboardNote::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'items' => isset($data['items']) ? collect($data['items'])->map(fn ($i) => [
                'text' => $i['text'],
                'checked' => $i['checked'] ?? false,
            ])->values()->toArray() : null,
            'is_pinned' => $data['is_pinned'] ?? false,
            'reminder_interval' => $interval,
            'reminder_at' => $remindAt,
            'reminder_day' => $day,
        ]);

        return response()->json(['note' => $this->format($note)], 201);
    }

    public function update(Request $request, DashboardNote $dashboardNote): JsonResponse
    {
        if ($dashboardNote->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:5000',
            'items' => 'sometimes|nullable|array',
            'items.*.text' => 'required_with:items|string|max:500',
            'items.*.checked' => 'boolean',
            'is_pinned' => 'boolean',
            'reminder_interval' => 'nullable|integer|in:10,15,30,45,60',
            'reminder_at' => 'nullable|date',
            'reminder_day' => 'nullable|integer|between:1,31',
        ]);

        if (isset($data['items'])) {
            $data['items'] = collect($data['items'])->map(fn ($i) => [
                'text' => $i['text'],
                'checked' => $i['checked'] ?? false,
            ])->values()->toArray();
        }

        // Reminder fields are mutually exclusive. If any was sent, normalise all
        // three and reset last_reminded_at (so a re-scheduled one-shot fires
        // again) plus monthly_reset_on (so the next monthly occurrence starts
        // with a fresh checklist).
        if (array_key_exists('reminder_interval', $data) || array_key_exists('reminder_at', $data) || array_key_exists('reminder_day', $data)) {
            [$interval, $remindAt, $day] = $this->resolveReminder($data);
            $data['reminder_interval'] = $interval;
            $data['reminder_at'] = $remindAt;
            $data['reminder_day'] = $day;
            $data['last_reminded_at'] = null;
            $data['monthly_reset_on'] = null;
        }

        $dashboardNote->update($data);

        return response()->json(['note' => $this->format($dashboardNote->fresh())]);
    }

    public function destroy(Request $request, DashboardNote $dashboardNote): JsonResponse
    {
        if ($dashboardNote->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $dashboardNote->delete();

        return response()->json(['ok' => true]);
    }

    private function format(DashboardNote $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body,
            'items' => $n->items,
            'is_pinned' => $n->is_pinned,
            'reminder_interval' => $n->reminder_interval,
            'reminder_at' => $n->reminder_at
                ? $n->reminder_at->copy()->timezone('Asia/Kolkata')->format('Y-m-d\TH:i')
                : null,
            'reminder_day' => $n->reminder_day,
            'reminder_due_today' => $n->reminder_day
                ? $n->isMonthlyDueOn(Carbon::now('Asia/Kolkata'))
                : false,
            'all_checked' => $n->items ? $n->allChecked() : null,
            'created_at' => $n->created_at->toIso8601String(),
            'updated_at' => $n->updated_at->toIso8601String(),
        ];
    }

    /**
     * Reminder choice is single-mode: a monthly day-of-month, a recurring
     * interval, or a one-shot datetime. Priority when several arrive (e.g. a
     * stale client): monthly day, then datetime, then interval. The datetime
     * is parsed as Asia/Kolkata so a "datetime-local" input from the user
     * matches what they meant on the dashboard.
     *
     * @return array{0: int|null, 1: \Carbon\Carbon|null, 2: int|null}
     */
    private function resolveReminder(array $data): array
    {
        $day = ! empty($data['reminder_day']) ? (int) $data['reminder_day'] : null;
        if ($day) {
            return [null, null, $day];
        }

        // Parse the datetime-local input as IST (the user's intent), then
        // convert to UTC so Eloquent's datetime cast (which writes the Carbon
        // instance's own format) stores the value in app timezone (UTC) and
        // reads it back as the same instant. Without ->utc(), the IST wall-
        // clock string was being written into the UTC column verbatim and the
        // formatter then re-shifted it by +5:30 on display.
        $remindAt = ! empty($data['reminder_at'])
            ? Carbon::parse($data['reminder_at'], 'Asia/Kolkata')->utc()
            : null;
        $interval = $remindAt ? null : ($data['reminder_interval'] ?? null);

        return [$interval, $remindAt, null];
    }
}
