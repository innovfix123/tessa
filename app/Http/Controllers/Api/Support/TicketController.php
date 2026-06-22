<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;

        $query = Ticket::with(['reporter:id,name', 'assignee:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('mine') && $request->input('mine') === '1') {
            $query->where(function ($q) use ($user) {
                $q->where('reporter_id', $user->id)
                    ->orWhere('assignee_id', $user->id);
            });
        }

        $leaderRoles = [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO, Role::SLUG_TECH_LEAD, Role::SLUG_OPS, Role::SLUG_ADMIN];
        if (! in_array($role, $leaderRoles, true)) {
            $query->where(function ($q) use ($user) {
                $q->where('reporter_id', $user->id)
                    ->orWhere('assignee_id', $user->id);
            });
        }

        $tickets = $query->get()->map(fn (Ticket $t) => $this->normalize($t));

        return response()->json(['ok' => true, 'tickets' => $tickets]);
    }

    public function assignees(Request $request): JsonResponse
    {
        $users = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return response()->json(['ok' => true, 'users' => $users]);
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $items = Ticket::with('reporter:id,name')
            ->where('assignee_id', $user->id)
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('created_at')
            ->get()
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'reporterName' => $t->reporter?->name ?? '',
                'createdAt' => $t->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category' => 'required|string|in:'.implode(',', Ticket::CATEGORIES),
            'priority' => 'required|string|in:'.implode(',', Ticket::PRIORITIES),
            'assignee_id' => 'nullable|integer|exists:users,id',
        ]);

        $user = $request->user();
        $category = $request->input('category');
        $assigneeId = (int) $request->input('assignee_id') ?: Ticket::resolveAssigneeId($category);

        if (! $assigneeId) {
            return response()->json(['error' => 'Could not resolve assignee for category: '.$category], 422);
        }

        $ticket = Ticket::create([
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'category' => $category,
            'priority' => $request->input('priority'),
            'status' => Ticket::STATUS_OPEN,
            'reporter_id' => $user->id,
            'assignee_id' => $assigneeId,
        ]);

        $ticket->load(['reporter:id,name', 'assignee:id,name']);

        $this->notifyAssignee($ticket);

        ActivityLogService::log(
            $user->id,
            'ticket_created',
            "{$user->name} created ticket: {$ticket->title}",
            'ticket',
            $ticket->id,
            ['category' => $ticket->category, 'priority' => $ticket->priority, 'assignee' => $ticket->assignee?->name]
        );

        return response()->json(['ok' => true, 'ticket' => $this->normalize($ticket)], 201);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:'.implode(',', Ticket::STATUSES),
        ]);

        $user = $request->user();
        $role = $user->role;
        $newStatus = $request->input('status');

        $canUpdate = $user->id === $ticket->assignee_id
            || $user->id === $ticket->reporter_id
            || in_array($role, [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_TECH_LEAD, Role::SLUG_ADMIN], true);

        if (! $canUpdate) {
            return response()->json(['error' => 'Not authorized to update this ticket'], 403);
        }

        $ticket->status = $newStatus;
        if ($newStatus === Ticket::STATUS_RESOLVED && ! $ticket->resolved_at) {
            $ticket->resolved_at = Carbon::now('Asia/Kolkata');
        }
        $ticket->save();
        $ticket->load(['reporter:id,name', 'assignee:id,name']);

        ActivityLogService::log(
            $user->id,
            'ticket_status_changed',
            "{$user->name} changed ticket #{$ticket->id} to {$newStatus}",
            'ticket',
            $ticket->id,
            ['new_status' => $newStatus, 'title' => $ticket->title]
        );

        return response()->json(['ok' => true, 'ticket' => $this->normalize($ticket)]);
    }

    private function normalize(Ticket $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'category' => $t->category,
            'priority' => $t->priority,
            'status' => $t->status,
            'reporterId' => $t->reporter_id,
            'reporterName' => $t->reporter?->name ?? '',
            'assigneeId' => $t->assignee_id,
            'assigneeName' => $t->assignee?->name ?? '',
            'resolvedAt' => $t->resolved_at?->toIso8601String(),
            'createdAt' => $t->created_at?->toIso8601String(),
            'updatedAt' => $t->updated_at?->toIso8601String(),
        ];
    }

    private function notifyAssignee(Ticket $ticket): void
    {
        try {
            $assignee = User::find($ticket->assignee_id);
            if (! $assignee) {
                return;
            }

            $priorityEmoji = match ($ticket->priority) {
                Ticket::PRIORITY_HIGH => '🔴',
                Ticket::PRIORITY_MEDIUM => '🟡',
                default => '⚪',
            };

            $message = "{$priorityEmoji} New {$ticket->category} ticket: {$ticket->title}\n"
                .'Priority: '.ucfirst($ticket->priority)."\n"
                ."Reported by: {$ticket->reporter?->name}\n"
                .($ticket->description ? "Details: {$ticket->description}" : '');

            $slack = app(SlackService::class);
            $slackUserId = $slack->getUserIdByName($assignee->name);
            if ($slackUserId) {
                $slack->sendDirectMessage($slackUserId, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('TicketController::notifyAssignee failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
