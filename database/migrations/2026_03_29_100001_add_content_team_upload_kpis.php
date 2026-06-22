<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Update Krishnan's existing text-type KPIs to upload-type
        KpiDefinition::where('user_id', 20)->where('field_key', 'ad_scripts_written')->update([
            'input_type' => 'upload',
            'upload_accept' => 'pdf,doc,docx,txt,rtf,odt',
            'upload_max_mb' => 20,
        ]);

        KpiDefinition::where('user_id', 20)->where('field_key', 'ai_videos_generated')->update([
            'input_type' => 'upload',
            'upload_accept' => 'mp4,mov,avi,mkv,webm',
            'upload_max_mb' => 100,
        ]);

        // Insert upload KPIs for content creators: Tiyasa (21), Maanasi (22), Disha (40)
        $creatorIds = [21, 22, 40];
        $fields = [
            [
                'field_key' => 'ad_scripts_written',
                'field_label' => 'Ad Scripts Written',
                'input_type' => 'upload',
                'upload_accept' => 'pdf,doc,docx,txt,rtf,odt',
                'upload_max_mb' => 20,
                'aggregation' => 'sum',
                'sort_order' => 1,
            ],
            [
                'field_key' => 'ai_videos_generated',
                'field_label' => 'AI Videos Generated',
                'input_type' => 'upload',
                'upload_accept' => 'mp4,mov,avi,mkv,webm',
                'upload_max_mb' => 100,
                'aggregation' => 'sum',
                'sort_order' => 2,
            ],
        ];

        foreach ($creatorIds as $userId) {
            foreach ($fields as $field) {
                KpiDefinition::firstOrCreate(
                    ['user_id' => $userId, 'field_key' => $field['field_key']],
                    array_merge($field, [
                        'user_id' => $userId,
                        'group_name' => 'Content',
                        'created_by' => 1,
                    ])
                );
            }
        }
    }

    public function down(): void
    {
        // Revert Krishnan's KPIs back to text type
        KpiDefinition::where('user_id', 20)->where('field_key', 'ad_scripts_written')->update([
            'input_type' => 'text',
            'upload_accept' => null,
            'upload_max_mb' => null,
        ]);
        KpiDefinition::where('user_id', 20)->where('field_key', 'ai_videos_generated')->update([
            'input_type' => 'text',
            'upload_accept' => null,
            'upload_max_mb' => null,
        ]);

        // Remove content creators' KPIs
        KpiDefinition::whereIn('user_id', [21, 22, 40])
            ->whereIn('field_key', ['ad_scripts_written', 'ai_videos_generated'])
            ->delete();
    }
};
