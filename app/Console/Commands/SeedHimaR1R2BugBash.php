<?php

namespace App\Console\Commands;

use App\Models\Bug;
use App\Models\Sprint;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedHimaR1R2BugBash extends Command
{
    protected $signature = 'seed:hima-r1r2-bugs
        {--dry-run : Print summary without writing to DB}
        {--csv= : Path to CSV (defaults to base_path/HIMA_All_Bugs_R1_R2_For_Devs.csv)}';

    protected $description = 'Seed Hima R1+R2 bug sprint (May 11-17 2026) from HIMA_All_Bugs_R1_R2_For_Devs.csv with Rishabh/Perumal complexity split';

    private const HIMA_PROJECT_ID = 1;
    private const HIMA_SQUAD_ID = 3;

    private const SPRINT_NAME = 'Hima R1+R2 Bug Bash - May 11 to May 17';
    private const SPRINT_GOAL = 'Burn down R1+R2 QA bugs from Raksha and multi-tester rounds. Architectural / race / lifecycle / billing bugs to Rishabh; UI / text / alignment / icons to Perumal.';
    private const START_DATE = '2026-05-11';
    private const END_DATE = '2026-05-17';

    private const CREATED_BY = 34;
    private const RISHABH = 35;
    private const PERUMAL = 37;

    private const FOUND_BY_MAP = [
        'Yuvanesh' => 34,
        'Perumal' => 37,
        'Rishabh' => 35,
        'Raksha' => 36,
        'Ranjini' => 27,
        'Iksha' => 53,
        'Tamilarasan' => 12,
        'Laxmi' => 23,
    ];

    public function handle(): int
    {
        $csvPath = $this->option('csv') ?: base_path('HIMA_All_Bugs_R1_R2_For_Devs.csv');
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");
            return self::FAILURE;
        }

        $rows = $this->readCsv($csvPath);
        $this->info(sprintf('Loaded %d rows from %s', count($rows), $csvPath));

        $assignment = $this->buildAssignmentMap();

        $missing = [];
        $extra = [];
        $csvIds = [];
        foreach ($rows as $row) {
            $csvIds[$row['ID']] = true;
            if (! isset($assignment[$row['ID']])) {
                $missing[] = $row['ID'];
            }
        }
        foreach (array_keys($assignment) as $mapId) {
            if (! isset($csvIds[$mapId])) {
                $extra[] = $mapId;
            }
        }
        if ($missing) {
            $this->error('CSV IDs missing from assignment map: ' . implode(', ', $missing));
            return self::FAILURE;
        }
        if ($extra) {
            $this->warn('Assignment-map IDs not found in CSV (ignored): ' . implode(', ', $extra));
        }

        [$rishabhCount, $perumalCount, $byPriority, $byPriorityAssignee] = $this->summarize($rows, $assignment);

        $this->info('=== Assignment Summary ===');
        $this->table(
            ['Priority', 'Total', 'Rishabh (35)', 'Perumal (37)'],
            [
                ['blocker (P0)', $byPriority['blocker'] ?? 0, $byPriorityAssignee['blocker'][self::RISHABH] ?? 0, $byPriorityAssignee['blocker'][self::PERUMAL] ?? 0],
                ['critical (P1)', $byPriority['critical'] ?? 0, $byPriorityAssignee['critical'][self::RISHABH] ?? 0, $byPriorityAssignee['critical'][self::PERUMAL] ?? 0],
                ['major (P2)', $byPriority['major'] ?? 0, $byPriorityAssignee['major'][self::RISHABH] ?? 0, $byPriorityAssignee['major'][self::PERUMAL] ?? 0],
                ['minor (P3+P4)', $byPriority['minor'] ?? 0, $byPriorityAssignee['minor'][self::RISHABH] ?? 0, $byPriorityAssignee['minor'][self::PERUMAL] ?? 0],
                ['TOTAL', count($rows), $rishabhCount, $perumalCount],
            ]
        );

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode - no DB writes performed.');
            return self::SUCCESS;
        }

        $existing = Sprint::where('name', self::SPRINT_NAME)
            ->where('project_id', self::HIMA_PROJECT_ID)
            ->first();
        if ($existing) {
            $this->error(sprintf('Sprint "%s" already exists (id=%d). Refusing to duplicate.', self::SPRINT_NAME, $existing->id));
            return self::FAILURE;
        }

        $newSprintId = null;
        DB::transaction(function () use ($rows, $assignment, &$newSprintId) {
            $sprint = Sprint::create([
                'name' => self::SPRINT_NAME,
                'goal' => self::SPRINT_GOAL,
                'project_id' => self::HIMA_PROJECT_ID,
                'squad_id' => self::HIMA_SQUAD_ID,
                'start_date' => self::START_DATE,
                'end_date' => self::END_DATE,
                'status' => Sprint::STATUS_PLANNING,
                'created_by' => self::CREATED_BY,
            ]);
            $newSprintId = $sprint->id;
            $this->info(sprintf('Created sprint #%d: %s', $sprint->id, $sprint->name));

            $sortOrder = 0;
            foreach ($rows as $row) {
                Bug::create([
                    'title' => $this->truncate(sprintf('[%s] %s', $row['ID'], $row['Title']), 255),
                    'description' => $row['Description'] ?: null,
                    'steps_to_reproduce' => $this->buildStepsField($row),
                    'project_id' => self::HIMA_PROJECT_ID,
                    'sprint_id' => $sprint->id,
                    'assignee_id' => $assignment[$row['ID']],
                    'reporter_id' => $this->resolveFoundBy($row['Found By'] ?? ''),
                    'status' => Bug::STATUS_OPEN,
                    'severity' => $this->mapSeverity($row['Severity'] ?? ''),
                    'priority' => $this->mapPriority($row['Priority'] ?? ''),
                    'environment' => 'production',
                    'created_by' => self::CREATED_BY,
                    'sort_order' => $sortOrder++,
                ]);
            }

            ActivityLogService::log(
                self::CREATED_BY,
                'sprint_created',
                sprintf('Yuvanesh created sprint: %s with %d bugs', $sprint->name, count($rows)),
                'sprint',
                $sprint->id
            );
        });

        $this->info("Done. Sprint id = {$newSprintId}. Verify with:");
        $this->line("  SELECT COUNT(*), assignee_id FROM bugs WHERE sprint_id={$newSprintId} GROUP BY assignee_id;");
        $this->line("  SELECT priority, COUNT(*) FROM bugs WHERE sprint_id={$newSprintId} GROUP BY priority;");
        return self::SUCCESS;
    }

    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);
            return [];
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        while (($data = fgetcsv($handle)) !== false) {
            if (empty(trim((string) ($data[0] ?? '')))) {
                continue;
            }
            $padded = array_pad($data, count($headers), '');
            $row = array_combine($headers, array_slice($padded, 0, count($headers)));
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function summarize(array $rows, array $assignment): array
    {
        $rishabhCount = 0;
        $perumalCount = 0;
        $byPriority = ['blocker' => 0, 'critical' => 0, 'major' => 0, 'minor' => 0];
        $byPriorityAssignee = [];
        foreach ($rows as $row) {
            $assignee = $assignment[$row['ID']];
            $priority = $this->mapPriority($row['Priority']);
            $byPriority[$priority] = ($byPriority[$priority] ?? 0) + 1;
            $byPriorityAssignee[$priority][$assignee] = ($byPriorityAssignee[$priority][$assignee] ?? 0) + 1;
            if ($assignee === self::RISHABH) {
                $rishabhCount++;
            } else {
                $perumalCount++;
            }
        }
        return [$rishabhCount, $perumalCount, $byPriority, $byPriorityAssignee];
    }

    private function buildStepsField(array $row): ?string
    {
        $parts = [];
        if (! empty($row['Notes'])) {
            $parts[] = trim((string) $row['Notes']);
        }
        $foundBy = trim((string) ($row['Found By'] ?? ''));
        if (str_contains($foundBy, ',')) {
            $confirmedCount = trim((string) ($row['Confirmed Count'] ?? ''));
            $parts[] = "Multi-tester confirmed by: {$foundBy}" . ($confirmedCount !== '' ? " (Confirmed Count: {$confirmedCount})" : '');
        }
        $module = trim((string) ($row['Module'] ?? ''));
        $round = trim((string) ($row['Round'] ?? ''));
        if ($module !== '' || $round !== '') {
            $parts[] = "Module: {$module} | Round: {$round}";
        }
        return $parts ? implode("\n", $parts) : null;
    }

    private function resolveFoundBy(string $foundBy): int
    {
        $first = trim(explode(',', $foundBy)[0]);
        return self::FOUND_BY_MAP[$first] ?? self::CREATED_BY;
    }

    private function mapSeverity(string $sev): string
    {
        $s = strtolower(trim($sev));
        return match ($s) {
            'critical', 'high' => 'high',
            'medium' => 'medium',
            'low', 'borderline' => 'low',
            default => 'medium',
        };
    }

    private function mapPriority(string $pri): string
    {
        $p = strtoupper(trim($pri));
        return match ($p) {
            'P0' => 'blocker',
            'P1' => 'critical',
            'P2' => 'major',
            'P3', 'P4' => 'minor',
            default => 'minor',
        };
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 3) . '...';
    }

    private function buildAssignmentMap(): array
    {
        $r = self::RISHABH;
        $p = self::PERUMAL;
        return [
            // ===== R1 P0 Critical (12) - all Rishabh =====
            'B001' => $r, 'B002' => $r, 'B003' => $r, 'B004' => $r,
            'B005' => $r, 'B006' => $r, 'B007' => $r, 'B008' => $r,
            'B009' => $r, 'B010' => $r, 'B011' => $r, 'B012' => $r,

            // ===== R1 P2 (90) - mixed =====
            'B013' => $p, 'B014' => $p, 'B015' => $p,
            'B016' => $r, 'B017' => $r, 'B018' => $r, 'B019' => $r,
            'B020' => $r, 'B021' => $r, 'B022' => $r, 'B023' => $r,
            'B024' => $r, 'B025' => $r,
            'B026' => $p,
            'B027' => $r, 'B028' => $r, 'B029' => $r, 'B030' => $r,
            'B031' => $r, 'B032' => $r, 'B033' => $r,
            'B034' => $p, 'B035' => $p, 'B036' => $p, 'B037' => $p,
            'B038' => $p, 'B039' => $p, 'B040' => $p, 'B041' => $p,
            'B042' => $r, 'B043' => $r,
            'B044' => $p,
            'B045' => $r,
            'B046' => $p,
            'B047' => $r, 'B048' => $r, 'B049' => $r, 'B050' => $r, 'B051' => $r,
            'B052' => $p, 'B053' => $p, 'B054' => $p, 'B055' => $p,
            'B056' => $r, 'B057' => $r,
            'B058' => $p, 'B059' => $p, 'B060' => $p,
            'B061' => $r,
            'B062' => $r, 'B063' => $p, 'B064' => $r, 'B065' => $r,
            'B066' => $r, 'B067' => $r, 'B068' => $r, 'B069' => $r,
            'B070' => $p, 'B071' => $r, 'B072' => $p, 'B073' => $p,
            'B074' => $r, 'B075' => $r, 'B076' => $r,
            'B077' => $p, 'B078' => $p, 'B079' => $p,
            'B080' => $r, 'B081' => $r, 'B082' => $r,
            'B083' => $p, 'B084' => $p,
            'B085' => $p, 'B086' => $p, 'B087' => $p, 'B088' => $p,
            'B089' => $p, 'B090' => $p, 'B091' => $p, 'B092' => $p,
            'B093' => $r, 'B094' => $r, 'B095' => $r, 'B096' => $r,
            'B097' => $p, 'B098' => $p,
            'B099' => $r,
            'B100' => $p,
            'B101' => $r, 'B102' => $r,

            // ===== R1 P3+P4 (23) - mixed =====
            'B103' => $p, 'B104' => $p, 'B105' => $p, 'B106' => $p,
            'B107' => $r,
            'B108' => $p,
            'B109' => $r,
            'B110' => $p,
            'B111' => $r,
            'B112' => $p, 'B113' => $p, 'B114' => $p, 'B115' => $p,
            'B116' => $r,
            'B117' => $p, 'B118' => $p, 'B119' => $p,
            'B120' => $r,
            'B121' => $p, 'B122' => $p,
            'B123' => $r, 'B124' => $r, 'B125' => $r,

            // ===== R2 P0 Critical (16) - all Rishabh =====
            'B127' => $r, 'B129' => $r, 'B133' => $r, 'B140' => $r,
            'B143' => $r, 'B144' => $r, 'B145' => $r, 'B156' => $r,
            'B163' => $r, 'B174' => $r, 'B176' => $r, 'B182' => $r,
            'B183' => $r, 'B186' => $r, 'B200' => $r, 'B202' => $r,

            // ===== R2 P1 High (30) - mixed =====
            'B126' => $r,
            'B130' => $r,
            'B131' => $r, 'B132' => $r, 'B134' => $r,
            'B135' => $p,
            'B142' => $r,
            'B146' => $r, 'B147' => $r, 'B148' => $r, 'B149' => $r,
            'B150' => $r, 'B151' => $r,
            'B156a' => $r,
            'B160' => $p, 'B161' => $p,
            'B162' => $r,
            'B173' => $p, 'B177' => $p, 'B178' => $p,
            'B181' => $r, 'B184' => $r, 'B188' => $r,
            'B190' => $r, 'B191' => $r,
            'B194' => $p,
            'B195' => $r, 'B198' => $r,
            'B201' => $p,
            'B204' => $r,

            // ===== R2 P3+P4 (33) - mixed =====
            'B128' => $r, 'B136' => $p, 'B137' => $r, 'B138' => $p,
            'B139' => $r, 'B141' => $r, 'B152' => $p, 'B153' => $r,
            'B154' => $r, 'B155' => $p, 'B158' => $r, 'B159' => $p,
            'B164' => $p, 'B165' => $p, 'B166' => $p, 'B167' => $p,
            'B168' => $p, 'B169' => $p, 'B170' => $p, 'B171' => $r,
            'B172' => $p, 'B175' => $p,
            'B179' => $p, 'B180' => $p,
            'B185' => $p, 'B187' => $p, 'B189' => $r, 'B192' => $r,
            'B193' => $p, 'B196' => $r, 'B197' => $p, 'B199' => $r,
            'B203' => $r,

            // ===== R1 Improvements (16) - mixed =====
            'I001' => $p, 'I002' => $r, 'I003' => $p, 'I004' => $r,
            'I005' => $r, 'I006' => $r, 'I007' => $p, 'I008' => $p,
            'I009' => $p, 'I010' => $p, 'I011' => $r, 'I012' => $p,
            'I013' => $p, 'I014' => $r, 'I015' => $r, 'I016' => $r,

            // ===== R2 Improvements (9) - mixed =====
            'I017' => $r, 'I018' => $p, 'I019' => $r, 'I020' => $r,
            'I021' => $r, 'I022' => $r, 'I023' => $p, 'I024' => $r,
            'I025' => $p,
        ];
    }
}
