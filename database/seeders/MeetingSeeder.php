<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

class MeetingSeeder extends Seeder
{
    public function run(): void
    {
        $nameToId = $this->buildNameToIdMap();

        $meetings = [
            ['meeting_key' => 'ops-standup-daily', 'title' => 'Team Standup', 'owner' => 'Sneha', 'day_of_week' => 'Monday', 'time' => '10:30 AM', 'recurrence' => 'daily_weekdays', 'portal' => 'ops', 'attendees' => ['Ranjini', 'Gousia', 'Reshma', 'Deeksha']],
        ];

        foreach ($meetings as $m) {
            $ownerId = $nameToId[$m['owner']] ?? null;
            $attendeeIds = array_values(array_unique(array_filter(array_map(fn ($name) => $nameToId[$name] ?? null, $m['attendees']))));
            $ownerUser = $ownerId ? User::find($ownerId) : null;

            Meeting::updateOrCreate(
                ['meeting_key' => $m['meeting_key']],
                [
                    'title' => $m['title'],
                    'owner' => $ownerUser?->name ?? $m['owner'],
                    'owner_id' => $ownerId,
                    'day_of_week' => $m['day_of_week'],
                    'time' => $m['time'],
                    'recurrence' => $m['recurrence'],
                    'portal' => $m['portal'],
                    'attendees' => $attendeeIds,
                    'created_by' => 0,
                ]
            );
        }
    }

    private function buildNameToIdMap(): array
    {
        $map = [];
        User::where('is_active', true)->get(['id', 'name'])->each(function (User $u) use (&$map) {
            $map[$u->name] = $u->id;
            $firstName = explode(' ', $u->name)[0] ?? '';
            if ($firstName && !isset($map[$firstName])) {
                $map[$firstName] = $u->id;
            }
        });
        return $map;
    }
}
