<?php

use App\Models\KpiDefinition;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $userId = 4; // Ayush
        $createdBy = 1; // JP
        $group = 'Network Leverage';
        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $fields = [
            ['field_key' => 'event_name',        'field_label' => 'Conference / Event Attended', 'aggregation' => null,    'input_type' => 'text',     'sort_order' => 0],
            ['field_key' => 'event_attendees',    'field_label' => 'Who Attended With You',       'aggregation' => null,    'input_type' => 'text',     'sort_order' => 1],
            ['field_key' => 'attendee_count',     'field_label' => 'Number of Attendees',         'aggregation' => 'sum',   'input_type' => 'text',     'sort_order' => 2],
            ['field_key' => 'leverage_contacts',  'field_label' => 'People Leveraged (Names)',    'aggregation' => null,    'input_type' => 'textarea', 'sort_order' => 3],
            ['field_key' => 'leverage_linkedin',  'field_label' => 'LinkedIn Profiles (Required)','aggregation' => null,    'input_type' => 'textarea', 'sort_order' => 4],
        ];

        foreach ($fields as $f) {
            KpiDefinition::create(array_merge($f, [
                'user_id' => $userId,
                'group_name' => $group,
                'created_by' => $createdBy,
                'effective_from' => $weekStart,
            ]));
        }
    }

    public function down(): void
    {
        KpiDefinition::where('user_id', 4)
            ->where('group_name', 'Network Leverage')
            ->forceDelete();
    }
};
