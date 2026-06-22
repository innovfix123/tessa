<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Announcements feed (Feature 8). Returns active announcements to the caller:
 * broadcast cards (target_user_id NULL — e.g. new-joiner) go to everyone, while
 * PERSONAL cards (target_user_id set — e.g. "your travel expense is paid") reach
 * only their target. Per-user dismissal happens client-side (localStorage).
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        $items = Announcement::active()
            ->where(function ($q) use ($uid) {
                $q->whereNull('target_user_id')        // broadcast to everyone
                    ->orWhere('target_user_id', $uid); // personal: only its target sees it
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'title' => $a->title,
                'body' => $a->body,
                'subject_user_id' => $a->subject_user_id,
                'target_user_id' => $a->target_user_id,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'announcements' => $items]);
    }
}
