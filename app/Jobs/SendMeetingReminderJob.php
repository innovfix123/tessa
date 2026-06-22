<?php

namespace App\Jobs;

use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMeetingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public Meeting $meeting,
        public string $type = 'reminder',
    ) {}

    public function handle(SlackService $slack): void
    {
        $meeting = $this->meeting;
        $ownerId = (int) $meeting->owner_id;
        $attendeeIds = collect($meeting->attendees ?? [])->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->values()->all();
        $ownerName = $meeting->ownerUser?->name ?? $meeting->owner;
        $attendeeNames = User::whereIn('id', $attendeeIds)->pluck('name', 'id');

        match ($this->type) {
            'started' => $this->sendStartedMessages($slack, $meeting, $ownerId, $attendeeIds, $ownerName, $attendeeNames),
            'mom' => $this->sendMomReminder($slack, $meeting, $ownerId),
            default => $this->sendPreReminder($slack, $meeting, $ownerId, $attendeeIds, $ownerName, $attendeeNames),
        };
    }

    private function sendPreReminder(SlackService $slack, Meeting $meeting, int $ownerId, array $attendeeIds, string $ownerName, $attendeeNames): void
    {
        $attendeeList = $attendeeNames->values()->filter()->implode(', ');
        $message = <<<MSG
        :bell: *Meeting Reminder*

        *{$meeting->title}* starts in 10 minutes!

        :clock3: *Time:* {$meeting->time}
        :bust_in_silhouette: *Owner:* {$ownerName}
        :busts_in_silhouette: *Attendees:* {$attendeeList}
        MSG;

        $recipientIds = collect($attendeeIds)->push($ownerId)->unique()->filter(fn ($id) => $id > 0)->values()->all();
        $this->sendToUsers($slack, $recipientIds, $message, 'reminder');
    }

    private function sendStartedMessages(SlackService $slack, Meeting $meeting, int $ownerId, array $attendeeIds, string $ownerName, $attendeeNames): void
    {
        $attendeeList = $attendeeNames->values()->filter()->implode(', ');

        if ($ownerId > 0) {
            $hostMsg = <<<MSG
            :mega: *Meeting Started*

            Your meeting *{$meeting->title}* has started!

            Please invite the attendees: *{$attendeeList}*
            MSG;

            $this->sendToUsers($slack, [$ownerId], $hostMsg, 'started-host');
        }

        $nonOwnerAttendees = collect($attendeeIds)->filter(fn ($id) => $id !== $ownerId)->values()->all();
        if (! empty($nonOwnerAttendees)) {
            $attendeeMsg = <<<MSG
            :mega: *Meeting Started*

            Your meeting *{$meeting->title}* with *{$ownerName}* has started!

            Please ask your host to add you to the meeting.
            MSG;

            $this->sendToUsers($slack, $nonOwnerAttendees, $attendeeMsg, 'started-attendee');
        }
    }

    private function sendMomReminder(SlackService $slack, Meeting $meeting, int $ownerId): void
    {
        if ($ownerId <= 0) {
            return;
        }

        $message = <<<MSG
        :memo: *MOM Reminder*

        Your meeting *{$meeting->title}* has ended.

        Please update the Minutes of Meeting (MOM) while it's still fresh!
        MSG;

        $this->sendToUsers($slack, [$ownerId], $message, 'mom');
    }

    private function sendToUsers(SlackService $slack, array $userIds, string $message, string $label): void
    {
        // Don't ping employees who are on leave today about a meeting (e.g. asking
        // them to update the MOM). WFH/Permission stay "working days" and are NOT
        // skipped (project rule: wfh_permission_not_leave). This check uses the
        // actual occurrence day, so every day of a multi-day leave is covered.
        $dateStr = Carbon::now('Asia/Kolkata')->toDateString();
        $onLeaveIds = LeaveRequest::whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->whereHas('leaveType', fn ($q) => $q->whereNotIn('slug', ['wfh', 'permission']))
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $userIds = array_values(array_diff($userIds, $onLeaveIds));
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            if (empty($user->email)) {
                Log::warning("Meeting {$label}: user has no email", [
                    'user_id' => $user->id,
                    'meeting' => $this->meeting->title,
                ]);

                continue;
            }

            $slackUserId = $slack->lookupByEmail($user->email);

            if (! $slackUserId) {
                Log::warning("Slack user not found for meeting {$label}", [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'meeting' => $this->meeting->title,
                ]);

                continue;
            }

            $slack->sendDirectMessage($slackUserId, $message);
        }

        Log::info("Meeting {$label} sent", [
            'meeting' => $this->meeting->title,
            'recipient_count' => $users->count(),
        ]);
    }
}
