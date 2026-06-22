<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Makes Sooraj's "Videos Delivered" upload field effectively size-dynamic:
 * the per-file ceiling is raised to 500 MB and the accept list broadened to
 * cover virtually any common video container, since we can't predict the
 * format or size of what he'll upload day to day.
 *
 * This 500 MB app-level cap is intentionally just BELOW the nginx
 * client_max_body_size (512M for tessa.innovfix.ai) and PHP FPM
 * upload_max_filesize/post_max_size (512M on PHP 8.3), so the friendly
 * client/server "file too large" message trips before a raw nginx 413.
 * Keep all three layers in sync if this value is ever changed again.
 */
return new class extends Migration
{
    private const EMAIL = 'sooraj@innovfix.in';

    private const VIDEO_ACCEPT = 'mp4,mov,avi,mkv,webm,m4v,wmv,flv,3gp,3g2,mpeg,mpg,mts,m2ts,ts,ogv,vob,f4v,divx';

    public function up(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if (! $sooraj) {
            return;
        }

        KpiDefinition::where('user_id', $sooraj->id)
            ->where('field_key', 'videos_delivered')
            ->update([
                'upload_accept' => self::VIDEO_ACCEPT,
                'upload_max_mb' => 500,
            ]);
    }

    public function down(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if ($sooraj) {
            KpiDefinition::where('user_id', $sooraj->id)
                ->where('field_key', 'videos_delivered')
                ->update([
                    'upload_accept' => 'mp4,mov,avi,mkv,webm',
                    'upload_max_mb' => 100,
                ]);
        }
    }
};
