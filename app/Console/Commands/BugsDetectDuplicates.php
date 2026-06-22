<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\BugDuplicateService;
use Illuminate\Console\Command;

class BugsDetectDuplicates extends Command
{
    protected $signature = 'bugs:detect-duplicates
                            {--project-id= : Only cluster bugs for this project (defaults to every project with active bugs)}';

    protected $description = 'AI-cluster active bugs into duplicate groups and persist duplicate_group_id on each row.';

    public function handle(BugDuplicateService $service): int
    {
        $projectId = $this->option('project-id');
        $projectIds = $projectId !== null
            ? [(int) $projectId]
            : Project::orderBy('id')->pluck('id')->all();

        $totalProcessed = 0;
        $totalGroups = 0;
        $totalDuplicates = 0;

        foreach ($projectIds as $pid) {
            $result = $service->detectAndStore($pid);
            $this->line(sprintf(
                'project=%d processed=%d groups=%d duplicates=%d%s',
                $pid,
                $result['processed'],
                $result['groups'],
                $result['duplicates'],
                $result['skipped'] ? ' SKIPPED('.$result['skipped'].')' : ''
            ));
            $totalProcessed += $result['processed'];
            $totalGroups += $result['groups'];
            $totalDuplicates += $result['duplicates'];
        }

        $this->info(sprintf(
            'Done. Processed %d bugs across %d projects → %d duplicate groups (%d bugs flagged).',
            $totalProcessed,
            count($projectIds),
            $totalGroups,
            $totalDuplicates
        ));

        return 0;
    }
}
