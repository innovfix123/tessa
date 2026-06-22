<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Supersedes 2026_05_24_000009: instead of a bespoke 500 MB / broad-format
 * cap (which would have required raising nginx + PHP host limits), Sooraj's
 * "Videos Delivered" field is aligned to the EXISTING content-creator video
 * convention — Krishnan's `ai_videos_generated`: accept `mp4,mov,avi,mkv,webm`,
 * max 100 MB. This keeps Sooraj consistent with how other content creators
 * (e.g. Krishnan) upload videos and needs no infrastructure change.
 *
 * (The 50 MB nginx client_max_body_size still effectively caps all video
 * uploads site-wide, content creators included — that is a pre-existing,
 * shared condition, unchanged here.)
 */
return new class extends Migration
{
    private const EMAIL = 'sooraj@innovfix.in';

    public function up(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if (! $sooraj) {
            return;
        }

        KpiDefinition::where('user_id', $sooraj->id)
            ->where('field_key', 'videos_delivered')
            ->update([
                'upload_accept' => 'mp4,mov,avi,mkv,webm',
                'upload_max_mb' => 100,
            ]);
    }

    public function down(): void
    {
        // No-op: this is a value realignment, not a structural change. The
        // field itself is created/removed by migration 000008.
    }
};
