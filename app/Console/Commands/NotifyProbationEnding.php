<?php

namespace App\Console\Commands;

use App\Models\ManagerNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyProbationEnding extends Command
{
    protected $signature = 'notify:probation-ending {--dry-run : Preview recipients without writing notifications or sending Slack}';

    protected $description = 'Notify HR the day before a team member\'s probation ends so they can release a completion/offer letter';

    public function handle(): void
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN — no notifications will be written or sent.');
        }

        // Fire exactly one day before probation ends. Interns carry a 15-day
        // probation window in the probation_* columns while their
        // employee_status stays 'intern', so match both statuses.
        $tomorrow = Carbon::now('Asia/Kolkata')->copy()->addDay()->toDateString();

        $users = User::whereIn('employee_status', ['probation', 'intern'])
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', $tomorrow)
            ->get();

        if ($users->isEmpty()) {
            $this->info("No probation periods ending tomorrow ({$tomorrow}).");

            return;
        }

        $slack = new SlackService;

        // The HR team receives the notification (per spec).
        $hrUsers = User::whereHas('roleRelation', fn ($q) => $q->whereIn('slug', [Role::SLUG_HR, Role::SLUG_HR_OPERATIONS]))
            ->where('is_active', true)
            ->get();

        if ($hrUsers->isEmpty()) {
            $this->warn('No active HR users to notify.');

            return;
        }

        $url = rtrim((string) config('app.url'), '/') . '/#view=letters';

        foreach ($users as $user) {
            $inApp = "{$user->name}'s probation ends tomorrow. You can now send them a Probation Completion Letter or Offer Letter.";
            $slackMsg = "🔔 *{$user->name}*'s probation ends tomorrow. You can now send them a *Probation Completion Letter* or *Offer Letter*. <{$url}|Release Letter>";

            foreach ($hrUsers as $hr) {
                if ($dryRun) {
                    $this->line("  would notify HR {$hr->name} (id {$hr->id}) about {$user->name}");

                    continue;
                }

                // In-app card carrying a Release Letter deep link (source_ref =
                // the candidate's user id). The dedup key resurfaces the same
                // row on a daily re-run instead of duplicating it.
                ManagerNotification::updateOrCreate(
                    [
                        'manager_id' => $hr->id,
                        'team_member_id' => $user->id,
                        'source' => 'probation_ending',
                        'source_ref' => (string) $user->id,
                    ],
                    ['message' => $inApp, 'dismissed_at' => null]
                );

                // Slack is best-effort; a failure must never block the in-app write.
                try {
                    $slackId = $slack->getUserIdByName($hr->name);
                    if ($slackId) {
                        $slack->sendDirectMessage($slackId, $slackMsg);
                    }
                } catch (\Throwable $e) {
                    Log::warning('NotifyProbationEnding: HR Slack DM failed', ['user' => $hr->name, 'error' => $e->getMessage()]);
                }
            }

            $this->info("Probation-ending notice for {$user->name} → {$hrUsers->count()} HR user(s).");
        }

        Log::info('NotifyProbationEnding: processed', ['count' => $users->count(), 'dry_run' => $dryRun]);
        $this->info("Processed {$users->count()} probation ending notification(s).");
    }
}
