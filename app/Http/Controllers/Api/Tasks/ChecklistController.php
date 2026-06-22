<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemCompletion;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecklistController extends Controller
{
    /**
     * GET /api/tessa/checklists/assignees
     * List of active users the current user can assign a checklist to.
     * Broader than TessaTaskController::myAssigneesOptions, which is scoped to
     * "people you've already assigned tasks to" — for a brand-new checklist
     * the assigner needs the full active roster.
     */
    public function assignees(Request $request): JsonResponse
    {
        $user = $request->user();
        $users = User::where('is_active', true)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return response()->json(['ok' => true, 'users' => $users]);
    }

    /**
     * GET /api/tessa/checklists?filter=mine|assigned
     * "mine" returns lists where I'm the assignee (with today's check state);
     * "assigned" returns lists I created (with today's completion count).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'mine');
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        if ($filter === 'assigned') {
            $checklists = Checklist::with(['items', 'assignee:id,name'])
                ->where('assigned_by', $user->id)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get();
        } else {
            $checklists = Checklist::with(['items', 'assigner:id,name'])
                ->where('assigned_to', $user->id)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get();
        }

        // Gather today's completion rows in a single query keyed by item_id
        // to avoid an N+1 loop while building the response. A row may exist
        // for a note-only update (checked_at NULL), so we fetch the full row
        // rather than just plucking checked_at.
        $itemIds = $checklists->flatMap(fn ($c) => $c->items->pluck('id'))->all();
        $completionsByItem = ChecklistItemCompletion::whereIn('checklist_item_id', $itemIds)
            ->where('check_date', $today)
            ->get()
            ->keyBy('checklist_item_id');

        $payload = $checklists->map(function (Checklist $c) use ($completionsByItem, $filter) {
            $items = $c->items->map(function (ChecklistItem $item) use ($completionsByItem, $filter) {
                $row = $completionsByItem->get($item->id);
                $checkedAt = $row?->checked_at;
                // For the assigner feed, suppress notes that have been
                // cleared from the dashboard; the assignee can still see
                // their own note text on their side regardless.
                $note = $row?->note;
                if ($filter === 'assigned' && $row && $row->assigner_dismissed_at) {
                    $note = null;
                }
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'position' => $item->position,
                    'checked_today' => $checkedAt !== null,
                    'checked_at' => $checkedAt ? Carbon::parse($checkedAt)->toIso8601String() : null,
                    'note_today' => $note,
                ];
            });
            $doneCount = $items->where('checked_today', true)->count();

            return [
                'id' => $c->id,
                'title' => $c->title,
                'description' => $c->description,
                'is_active' => (bool) $c->is_active,
                'assigner' => $c->assigner ? ['id' => $c->assigner->id, 'name' => $c->assigner->name] : null,
                'assignee' => $c->assignee ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
                'role' => $filter === 'assigned' ? 'assigner' : 'assignee',
                'items' => $items,
                'item_count' => $items->count(),
                'done_today' => $doneCount,
            ];
        });

        return response()->json(['ok' => true, 'checklists' => $payload]);
    }

    /**
     * POST /api/tessa/checklists
     * Body: { assigned_to, title, description?, items: [string, ...] }
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'assigned_to' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*' => 'required|string|max:300',
        ]);

        $checklist = DB::transaction(function () use ($user, $validated) {
            $c = Checklist::create([
                'assigned_by' => $user->id,
                'assigned_to' => (int) $validated['assigned_to'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]);

            foreach ($validated['items'] as $i => $title) {
                $title = trim((string) $title);
                if ($title === '') continue;
                ChecklistItem::create([
                    'checklist_id' => $c->id,
                    'title' => $title,
                    'position' => $i,
                ]);
            }

            return $c;
        });

        $assignee = User::find($checklist->assigned_to);
        ActivityLogService::log(
            $user->id,
            'checklist_assigned',
            "{$user->name} assigned checklist \"{$checklist->title}\" to ".($assignee?->name ?? '?')
        );

        return response()->json(['ok' => true, 'id' => $checklist->id], 201);
    }

    /**
     * PATCH /api/tessa/checklists/{checklist}
     * Edit title/description/items. Assigner only. items[] is a full
     * replacement of the item set (preserves ids you pass back so prior
     * completions don't get orphaned).
     */
    public function update(Request $request, Checklist $checklist): JsonResponse
    {
        $user = $request->user();
        if ($checklist->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the assigner can edit this checklist'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string|max:2000',
            'items' => 'sometimes|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.title' => 'required_with:items|string|max:300',
        ]);

        DB::transaction(function () use ($checklist, $validated) {
            if (array_key_exists('title', $validated)) {
                $checklist->title = $validated['title'];
            }
            if (array_key_exists('description', $validated)) {
                $checklist->description = $validated['description'];
            }
            $checklist->save();

            if (isset($validated['items'])) {
                $keptIds = [];
                foreach ($validated['items'] as $i => $item) {
                    $title = trim((string) $item['title']);
                    if ($title === '') continue;
                    if (! empty($item['id'])) {
                        $existing = ChecklistItem::where('checklist_id', $checklist->id)->find($item['id']);
                        if ($existing) {
                            $existing->update(['title' => $title, 'position' => $i]);
                            $keptIds[] = $existing->id;
                            continue;
                        }
                    }
                    $new = ChecklistItem::create([
                        'checklist_id' => $checklist->id,
                        'title' => $title,
                        'position' => $i,
                    ]);
                    $keptIds[] = $new->id;
                }
                // Remove items that the assigner dropped — their completions
                // cascade away with the row.
                ChecklistItem::where('checklist_id', $checklist->id)
                    ->whereNotIn('id', $keptIds)
                    ->delete();
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/tessa/checklists/{checklist}
     * Soft-deactivate rather than hard-delete, so the assignee's history stays
     * intact for audit. Assigner only.
     */
    public function destroy(Request $request, Checklist $checklist): JsonResponse
    {
        $user = $request->user();
        if ($checklist->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the assigner can delete this checklist'], 403);
        }

        $checklist->update(['is_active' => false]);

        ActivityLogService::log($user->id, 'checklist_removed', "{$user->name} removed checklist \"{$checklist->title}\"");

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/tessa/checklists/{checklist}/items/{item}/toggle
     * Body: { checked: bool, note?: string } — assignee toggles a box for
     * today (IST date) and optionally saves a same-row update note.
     */
    public function toggleCheck(Request $request, Checklist $checklist, ChecklistItem $item): JsonResponse
    {
        $user = $request->user();

        if ($checklist->assigned_to !== $user->id) {
            return response()->json(['error' => 'Only the assignee can check items on this checklist'], 403);
        }
        if ($item->checklist_id !== $checklist->id) {
            return response()->json(['error' => 'Item does not belong to this checklist'], 422);
        }
        if (! $checklist->is_active) {
            return response()->json(['error' => 'This checklist has been removed'], 422);
        }

        $checked = (bool) $request->input('checked', true);
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $row = ChecklistItemCompletion::firstOrNew([
            'checklist_item_id' => $item->id,
            'user_id' => $user->id,
            'check_date' => $today,
        ]);
        $row->checked_at = $checked ? now() : null;
        if ($request->has('note')) {
            $note = trim((string) $request->input('note'));
            $newNote = $note === '' ? null : $note;
            // A fresh note from the assignee should re-surface on the
            // assigner's dashboard even if they cleared earlier today.
            if ($newNote !== $row->note) {
                $row->assigner_dismissed_at = null;
            }
            $row->note = $newNote;
        }
        $row->save();

        return response()->json([
            'ok' => true,
            'checked_today' => $checked,
            'checked_at' => $row->checked_at?->toIso8601String(),
            'note_today' => $row->note,
        ]);
    }

    /**
     * POST /api/tessa/checklists/{checklist}/items/{item}/note
     * Body: { note: string|null } — assignee saves the optional daily update
     * text adjacent to an item. Independent of the checked state so notes
     * can be authored before *or* after ticking (or without ticking at all).
     */
    public function saveNote(Request $request, Checklist $checklist, ChecklistItem $item): JsonResponse
    {
        $user = $request->user();

        if ($checklist->assigned_to !== $user->id) {
            return response()->json(['error' => 'Only the assignee can update notes on this checklist'], 403);
        }
        if ($item->checklist_id !== $checklist->id) {
            return response()->json(['error' => 'Item does not belong to this checklist'], 422);
        }
        if (! $checklist->is_active) {
            return response()->json(['error' => 'This checklist has been removed'], 422);
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);
        $note = isset($validated['note']) ? trim((string) $validated['note']) : '';
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $row = ChecklistItemCompletion::firstOrNew([
            'checklist_item_id' => $item->id,
            'user_id' => $user->id,
            'check_date' => $today,
        ]);
        $newNote = $note === '' ? null : $note;
        if ($newNote !== $row->note) {
            $row->assigner_dismissed_at = null;
        }
        $row->note = $newNote;
        $row->save();

        return response()->json([
            'ok' => true,
            'note_today' => $row->note,
            'checked_today' => $row->checked_at !== null,
        ]);
    }

    /**
     * POST /api/tessa/checklists/updates/clear
     * Assigner clears today's update feed on their dashboard. Dismisses
     * every same-day note row across checklists they own; the assignee's
     * note text is preserved (we just stamp dismissed_at). If the assignee
     * later edits a note, that row's dismissal is reset and it re-appears.
     */
    public function clearUpdates(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $itemIds = ChecklistItem::whereIn(
            'checklist_id',
            Checklist::where('assigned_by', $user->id)->pluck('id')
        )->pluck('id');

        $cleared = ChecklistItemCompletion::whereIn('checklist_item_id', $itemIds)
            ->where('check_date', $today)
            ->whereNotNull('note')
            ->whereNull('assigner_dismissed_at')
            ->update(['assigner_dismissed_at' => now()]);

        return response()->json(['ok' => true, 'cleared' => $cleared]);
    }
}
