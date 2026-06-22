<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ManagerNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerNotificationController extends Controller
{
    /**
     * GET /api/manager-notifications
     * Returns the caller's undismissed notifications, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $items = ManagerNotification::active()
            ->forManager($request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'team_member_id', 'source', 'source_ref', 'message', 'updated_at'])
            ->map(fn ($n) => [
                'id' => $n->id,
                'team_member_id' => $n->team_member_id,
                'source' => $n->source,
                'source_ref' => $n->source_ref,
                'message' => $n->message,
                'at' => $n->updated_at->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * POST /api/manager-notifications/clear
     * Stamp dismissed_at on every active row owned by the caller. A later
     * resubmission of the same choice resets dismissed_at to null and the
     * row resurfaces — same semantics as ChecklistController::clearUpdates.
     */
    public function clear(Request $request): JsonResponse
    {
        $cleared = ManagerNotification::active()
            ->forManager($request->user()->id)
            ->update(['dismissed_at' => now()]);

        return response()->json(['ok' => true, 'cleared' => $cleared]);
    }
}
