<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds a second, separate upload KPI to Sooraj's daily report so he can
 * upload videos independently of his existing "Designs Delivered" images
 * field. Images and videos are intentionally two distinct fields — the
 * design field keeps its image/PDF accept list, this one only accepts
 * video formats (same accept list + max as the existing
 * `ai_videos_generated` content-team field). Both are multi-file uploads
 * handled generically by CreativeUploadController, and fileThumbHtml()
 * already renders video thumbnails.
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

        KpiDefinition::firstOrCreate(
            ['user_id' => $sooraj->id, 'field_key' => 'videos_delivered'],
            [
                'user_id' => $sooraj->id,
                'group_name' => 'Design',
                'field_key' => 'videos_delivered',
                'field_label' => 'Videos Delivered',
                'input_type' => 'upload',
                'upload_accept' => 'mp4,mov,avi,mkv,webm',
                'upload_max_mb' => 100,
                'aggregation' => 'sum',
                'sort_order' => 1,
                'created_by' => 1,
            ]
        );
    }

    public function down(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if ($sooraj) {
            KpiDefinition::where('user_id', $sooraj->id)
                ->where('field_key', 'videos_delivered')
                ->delete();
        }
    }
};
