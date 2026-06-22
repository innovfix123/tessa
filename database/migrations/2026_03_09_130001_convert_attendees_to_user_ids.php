<?php

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Convert attendees JSON from name strings to user IDs.
     */
    public function up(): void
    {
        Meeting::all()->each(function (Meeting $meeting) {
            $attendees = $meeting->attendees ?? [];
            if (empty($attendees)) {
                return;
            }

            $ids = [];
            foreach ($attendees as $item) {
                if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                    $ids[] = (int) $item;
                    continue;
                }

                $name = trim((string) $item);
                if ($name === '') {
                    continue;
                }

                $user = User::where('name', $name)
                    ->where('is_active', true)
                    ->first()
                    ?? User::where('name', 'like', $name . ' %')
                        ->where('is_active', true)
                        ->orderByRaw('LENGTH(name) DESC')
                        ->first();

                if ($user) {
                    $ids[] = $user->id;
                }
            }

            $meeting->update(['attendees' => array_values(array_unique($ids))]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Meeting::all()->each(function (Meeting $meeting) {
            $attendees = $meeting->attendees ?? [];
            if (empty($attendees)) {
                return;
            }

            $names = [];
            foreach ($attendees as $item) {
                if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                    $names[] = $item;
                    continue;
                }
                $user = User::find((int) $item);
                if ($user) {
                    $names[] = $user->name;
                }
            }

            $meeting->update(['attendees' => $names]);
        });
    }
};
