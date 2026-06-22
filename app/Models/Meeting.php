<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    protected $fillable = [
        'meeting_key',
        'title',
        'owner',
        'owner_id',
        'day_of_week',
        'meeting_date',
        'time',
        'recurrence',
        'portal',
        'attendees',
        'attendees_only',
        'agenda_template_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'attendees' => 'array',
            'attendees_only' => 'boolean',
            'meeting_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function agendaTemplate(): BelongsTo
    {
        return $this->belongsTo(AgendaTemplate::class, 'agenda_template_id');
    }

    public function discussionPoints(): HasMany
    {
        return $this->hasMany(DiscussionPoint::class, 'meeting_id', 'meeting_key');
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'meeting_id', 'meeting_key');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MeetingNote::class, 'meeting_id', 'meeting_key');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class, 'meeting_id', 'meeting_key');
    }

    /**
     * The storage key for this meeting's agenda/notes on a given weekday.
     *
     * Multi-day recurrences store each weekday in its own slot so a Tuesday MOM
     * doesn't clobber Monday's. daily_weekdays keeps Monday on the bare key for
     * backwards-compat; every other occurrence is suffixed (-tue/-wed/-thu/-fri).
     * Single-occurrence meetings always use the bare meeting_key.
     *
     * Canonical mapping — mirrored by the JS client (meeting.js expandMeetings).
     * Reuse this everywhere a day-specific agenda/MOM key is needed.
     */
    public function effectiveKeyForDay(string $dayName): string
    {
        $multiDay = [
            'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'mon_thu'        => ['Monday', 'Thursday'],
            'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
        ];

        $recurrence = $this->recurrence ?? '';
        if (! isset($multiDay[$recurrence])) {
            return $this->meeting_key;
        }

        if ($recurrence === 'daily_weekdays' && $dayName === 'Monday') {
            return $this->meeting_key;
        }

        return $this->meeting_key.'-'.strtolower(substr($dayName, 0, 3));
    }
}
