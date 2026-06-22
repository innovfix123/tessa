<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\MetaAdReport;
use App\Models\Role;
use App\Services\ActivityLogService;
use App\Services\RegionSpendAggregationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MetaAdReportController extends Controller
{
    private const ALLOWED_ROLES = [
        Role::SLUG_CEO,
        Role::SLUG_CFO,
        Role::SLUG_CMO,
        Role::SLUG_COO,
        Role::SLUG_TECH_LEAD,
        Role::SLUG_MARKETING,
        Role::SLUG_GROWTH_MANAGER,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $project = $request->input('project', '');
        $query = MetaAdReport::query();

        if ($project && array_key_exists($project, MetaAdReport::PROJECTS)) {
            $query->where('project', $project);
        }
        if ($request->filled('from')) {
            $query->where('reporting_starts', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('reporting_ends', '<=', $request->input('to'));
        }
        if ($request->filled('campaign')) {
            $query->where('campaign_name', 'like', '%' . $request->input('campaign') . '%');
        }

        $reports = $query->orderByDesc('reporting_starts')
            ->orderByDesc('amount_spent')
            ->limit(500)
            ->get();

        // Build summary
        $summary = [
            'total_spend' => $reports->sum('amount_spent'),
            'total_impressions' => $reports->sum('impressions'),
            'total_reach' => $reports->sum('reach'),
            'total_installs' => $reports->sum('app_installs'),
            'total_results' => $reports->sum('results'),
            'total_first_purchases' => $reports->sum('new_user_first_purchase'),
            'row_count' => $reports->count(),
        ];

        $items = $reports->map(function ($r) {
            return [
                'id' => $r->id,
                'project' => $r->project,
                'campaign_name' => $r->campaign_name,
                'ad_set_name' => $r->ad_set_name,
                'ad_name' => $r->ad_name,
                'reach' => (int) $r->reach,
                'impressions' => (int) $r->impressions,
                'frequency' => round((float) $r->frequency, 2),
                'result_type' => $r->result_type,
                'results' => (int) $r->results,
                'amount_spent' => round((float) $r->amount_spent, 2),
                'cost_per_result' => $r->cost_per_result ? round((float) $r->cost_per_result, 2) : null,
                'cpc' => $r->cpc ? round((float) $r->cpc, 2) : null,
                'cpm' => $r->cpm ? round((float) $r->cpm, 2) : null,
                'ctr' => $r->ctr ? round((float) $r->ctr, 2) : null,
                'app_installs' => (int) $r->app_installs,
                'cost_per_install' => $r->cost_per_install ? round((float) $r->cost_per_install, 2) : null,
                'new_user_first_purchase' => (int) $r->new_user_first_purchase,
                'cost_per_first_purchase' => $r->cost_per_first_purchase ? round((float) $r->cost_per_first_purchase, 2) : null,
                'reporting_starts' => $r->reporting_starts->format('Y-m-d'),
                'reporting_ends' => $r->reporting_ends->format('Y-m-d'),
            ];
        });

        // Get unique reporting dates for the date picker
        $dates = MetaAdReport::selectRaw('DISTINCT reporting_starts')
            ->orderByDesc('reporting_starts')
            ->limit(60)
            ->pluck('reporting_starts')
            ->map(fn ($d) => $d->format('Y-m-d'));

        // Build date coverage per project for last 7 days. Includes both
        // per-campaign uploads (meta_ad_reports) AND region uploads
        // (region_ad_spend_cache, source=meta) so region-only uploads still
        // light up the tracker green.
        $uploadedDates = MetaAdReport::selectRaw('project, reporting_starts, COUNT(*) as row_count, SUM(amount_spent) as day_spend')
            ->groupBy('project', 'reporting_starts')
            ->orderByDesc('reporting_starts')
            ->get();

        $uploadMap = [];
        foreach ($uploadedDates as $r) {
            $uploadMap[$r->project][$r->reporting_starts->format('Y-m-d')] = [
                'rows'  => (int) $r->row_count,
                'spend' => (float) $r->day_spend,
            ];
        }

        $regionRows = \DB::table('region_ad_spend_cache')
            ->where('source', 'meta')
            ->select('project', 'reporting_date', \DB::raw('COUNT(*) as row_count'), \DB::raw('SUM(amount) as day_spend'))
            ->groupBy('project', 'reporting_date')
            ->get();
        foreach ($regionRows as $r) {
            $key = Carbon::parse($r->reporting_date)->format('Y-m-d');
            if (! isset($uploadMap[$r->project][$key])) {
                $uploadMap[$r->project][$key] = [
                    'rows'  => (int) $r->row_count,
                    'spend' => (float) $r->day_spend,
                ];
            }
        }

        $coverage = [];
        $today = DateHelper::today();
        // Tracker starts at yesterday — today's ads are uploaded next morning so
        // a "today missing" card is always false-red noise.
        foreach (MetaAdReport::PROJECTS as $slug => $label) {
            $days = [];
            for ($i = 1; $i <= 7; $i++) {
                $key = $today->copy()->subDays($i)->format('Y-m-d');
                $day = $today->copy()->subDays($i)->format('D');
                $info = $uploadMap[$slug][$key] ?? null;
                $days[] = [
                    'date' => $key,
                    'day' => $day,
                    'uploaded' => $info !== null,
                    'rows' => $info ? $info['rows'] : 0,
                    'spend' => $info ? round($info['spend'], 2) : 0,
                ];
            }
            $coverage[$slug] = $days;
        }

        // Top-level summary: include matching region uploads in total_spend.
        $regionSummaryQuery = \DB::table('region_ad_spend_cache')->where('source', 'meta');
        if ($project && array_key_exists($project, MetaAdReport::PROJECTS)) {
            $regionSummaryQuery->where('project', $project);
        }
        if ($request->filled('from')) {
            $regionSummaryQuery->where('reporting_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $regionSummaryQuery->where('reporting_date', '<=', $request->input('to'));
        }
        // Avoid double-counting on dates that already have campaign uploads.
        $campaignPairsQuery = MetaAdReport::query();
        if ($project && array_key_exists($project, MetaAdReport::PROJECTS)) {
            $campaignPairsQuery->where('project', $project);
        }
        $existingPairs = $campaignPairsQuery->select('project', 'reporting_starts')->distinct()->get()
            ->map(fn ($r) => $r->project . '|' . $r->reporting_starts->format('Y-m-d'))
            ->all();

        $regionSpendOnly = (clone $regionSummaryQuery)
            ->select('project', 'reporting_date', \DB::raw('SUM(amount) as day_spend'))
            ->groupBy('project', 'reporting_date')
            ->get();
        $regionExtra = 0.0;
        foreach ($regionSpendOnly as $r) {
            $key = $r->project . '|' . Carbon::parse($r->reporting_date)->format('Y-m-d');
            if (! in_array($key, $existingPairs, true)) {
                $regionExtra += (float) $r->day_spend;
            }
        }
        $summary['total_spend'] = round((float) $summary['total_spend'] + $regionExtra, 2);

        // Per-date region breakdown so the UI can show region-only uploads as rows.
        $regionListQuery = \DB::table('region_ad_spend_cache')->where('source', 'meta');
        if ($project && array_key_exists($project, MetaAdReport::PROJECTS)) {
            $regionListQuery->where('project', $project);
        }
        if ($request->filled('from')) {
            $regionListQuery->where('reporting_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $regionListQuery->where('reporting_date', '<=', $request->input('to'));
        }
        $regionRowsList = $regionListQuery->orderByDesc('reporting_date')->limit(2000)->get();

        $regionUploads = [];
        foreach ($regionRowsList as $r) {
            $date = Carbon::parse($r->reporting_date)->format('Y-m-d');
            $key = $r->project . '|' . $date;
            if (! isset($regionUploads[$key])) {
                $regionUploads[$key] = [
                    'date' => $date,
                    'project' => $r->project,
                    'languages' => [],
                    'total' => 0.0,
                ];
            }
            $regionUploads[$key]['languages'][$r->language] = round((float) $r->amount, 2);
            $regionUploads[$key]['total'] += (float) $r->amount;
        }
        $regionUploads = array_values(array_map(function ($row) {
            $row['total'] = round((float) $row['total'], 2);
            return $row;
        }, $regionUploads));

        return response()->json([
            'ok' => true,
            'reports' => $items,
            'summary' => $summary,
            'dates' => $dates,
            'coverage' => $coverage,
            'projects' => MetaAdReport::PROJECTS,
            'region_uploads' => $regionUploads,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $action = $request->input('action', 'upload');

        if ($action === 'delete') {
            return $this->handleDelete($request, $user);
        }

        // Auto-detect by sniffing the CSV header so the upload works even if the
        // wrong radio is selected (or the modal is loading a stale cached JS).
        $detected = $this->detectCsvType($request);
        $type = $detected ?: $request->input('type');

        if ($type === 'region') {
            return $this->handleRegionUpload($request, $user);
        }

        return $this->handleUpload($request, $user);
    }

    /**
     * Sniff the CSV header to decide whether this is a per-campaign export or
     * a region-level export. Returns 'region', 'campaign', or null.
     */
    private function detectCsvType(Request $request): ?string
    {
        $file = $request->file('file');
        if (! $file) return null;

        $content = @file_get_contents($file->getRealPath());
        if ($content === false || $content === '') return null;

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $firstLine = strtok($content, "\n") ?: '';

        // The new per-ad-set export has "Ad set name" + "Amount spent" and NO
        // "Campaign name". The per-campaign export also has "Ad set name" but adds
        // "Campaign name", so branch on that first.
        $hasCampaignCol = stripos($firstLine, 'Campaign name') !== false;
        $hasAdSet       = stripos($firstLine, 'Ad set name')   !== false;
        $hasAmount      = stripos($firstLine, 'Amount spent')  !== false;

        if ($hasCampaignCol) return 'campaign';
        if ($hasAdSet && $hasAmount) return 'region';
        return null;
    }

    private function handleUpload(Request $request, $user): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:20480',
            'file2' => 'nullable|file|mimes:csv,txt|max:20480',
            'file3' => 'nullable|file|mimes:csv,txt|max:20480',
            'project' => 'required|in:' . implode(',', array_keys(MetaAdReport::PROJECTS)),
        ]);

        $project = $request->input('project');
        $files = array_values(array_filter([$request->file('file'), $request->file('file2'), $request->file('file3')]));

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $totalRows = 0;
        $names = [];

        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $names[] = $originalName;
            $path = $file->store('meta_ad_reports', 'public');

            try {
                $rows = $this->parseCsv(file_get_contents($file->getRealPath()));
            } catch (\Exception $e) {
                Storage::disk('public')->delete($path);
                Log::error('MetaAdReport CSV parse failed', ['file' => $originalName, 'error' => $e->getMessage()]);
                return response()->json(['error' => "Failed to parse {$originalName}: " . $e->getMessage()], 422);
            }

            if (empty($rows)) {
                Storage::disk('public')->delete($path);
                return response()->json(['error' => "No valid data rows found in {$originalName}."], 422);
            }

            $totalRows += count($rows);

            foreach ($rows as $i => $row) {
                try {
                    $hash = hash('sha256', implode('|', [
                        $project,
                        $row['campaign_name'],
                        $row['ad_set_name'],
                        $row['ad_name'],
                        $row['reporting_starts'],
                        $row['reporting_ends'],
                    ]));

                    if (MetaAdReport::where('row_hash', $hash)->exists()) {
                        $skipped++;
                        continue;
                    }

                    MetaAdReport::create(array_merge($row, [
                        'project' => $project,
                        'uploaded_by' => $user->id,
                        'source_file' => $originalName,
                        'row_hash' => $hash,
                    ]));
                    $inserted++;
                } catch (\Exception $e) {
                    $errors[] = $originalName . ' row ' . ($i + 2) . ': ' . $e->getMessage();
                    Log::warning('MetaAdReport row import failed', [
                        'file' => $originalName,
                        'row' => $i + 2,
                        'error' => $e->getMessage(),
                        'data' => $row,
                    ]);
                }
            }
        }

        ActivityLogService::log(
            $user->id,
            'meta_ad_report_upload',
            'Uploaded ' . implode(' + ', $names) . ": {$inserted} inserted, {$skipped} skipped",
            'meta_ad_report',
            null,
            ['files' => $names, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => count($errors)]
        );

        return response()->json([
            'ok' => true,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'total_rows' => $totalRows,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    private function parseCsv(string $content): array
    {
        // Strip UTF-8 BOM if present (common in Meta Ads Manager exports)
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // Normalize line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = str_getcsv($content, "\n");
        if (count($lines) < 2) {
            return [];
        }

        // Parse header
        $header = str_getcsv($lines[0]);
        $header = array_map('trim', $header);

        // Map CSV column names to our DB column names
        $columnMap = [
            'Campaign name' => 'campaign_name',
            'Ad set name' => 'ad_set_name',
            'Ad name' => 'ad_name',
            'Reach' => 'reach',
            'Impressions' => 'impressions',
            'Frequency' => 'frequency',
            'Result Type' => 'result_type',
            'Results' => 'results',
            'Amount spent (INR)' => 'amount_spent',
            'Cost per result' => 'cost_per_result',
            'CPC (cost per link click)' => 'cpc',
            'CPM (cost per 1,000 impressions)' => 'cpm',
            'CTR (link click-through rate)' => 'ctr',
            'App Installs' => 'app_installs',
            'Cost per App Install' => 'cost_per_install',
            'new_user_first_purchase' => 'new_user_first_purchase',
            'Cost per new_user_first_purchase' => 'cost_per_first_purchase',
            'Reporting starts' => 'reporting_starts',
            'Reporting ends' => 'reporting_ends',
        ];

        // Build index mapping
        $indices = [];
        foreach ($header as $i => $col) {
            if (isset($columnMap[$col])) {
                $indices[$columnMap[$col]] = $i;
            }
        }

        // Verify required columns
        $required = ['campaign_name', 'ad_set_name', 'ad_name', 'reporting_starts', 'reporting_ends'];
        foreach ($required as $req) {
            if (! isset($indices[$req])) {
                throw new \RuntimeException("Missing required column mapping for: {$req}");
            }
        }

        $rows = [];
        for ($lineNum = 1; $lineNum < count($lines); $lineNum++) {
            $line = trim($lines[$lineNum]);
            if ($line === '') {
                continue;
            }

            $cols = str_getcsv($line);

            $row = [];
            foreach ($indices as $dbCol => $csvIdx) {
                $val = isset($cols[$csvIdx]) ? trim($cols[$csvIdx]) : '';

                if (in_array($dbCol, ['reporting_starts', 'reporting_ends'])) {
                    $row[$dbCol] = $val ?: null;
                } elseif (in_array($dbCol, ['campaign_name', 'ad_set_name', 'ad_name', 'result_type'])) {
                    $row[$dbCol] = $val;
                } elseif (in_array($dbCol, ['reach', 'impressions', 'results', 'app_installs', 'new_user_first_purchase'])) {
                    $row[$dbCol] = $val !== '' ? (int) $val : 0;
                } else {
                    // Decimal fields
                    $row[$dbCol] = $val !== '' ? (float) $val : null;
                }
            }

            // Skip rows without essential data
            if (empty($row['campaign_name']) || empty($row['reporting_starts'])) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function handleRegionUpload(Request $request, $user): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:20480',
            'file2' => 'nullable|file|mimes:csv,txt|max:20480',
            'file3' => 'nullable|file|mimes:csv,txt|max:20480',
            'project' => 'required|in:' . implode(',', array_keys(MetaAdReport::PROJECTS)),
        ]);

        $project = $request->input('project');
        $files = array_values(array_filter([$request->file('file'), $request->file('file2'), $request->file('file3')]));
        $names = array_map(fn ($f) => $f->getClientOriginalName(), $files);
        $originalName = implode(' + ', $names);

        try {
            // Parse every uploaded file (one per ad account) and pool the rows.
            $rows = [];
            foreach ($files as $f) {
                $rows = array_merge($rows, $this->parseRegionCsv(file_get_contents($f->getRealPath())));
            }

            if (empty($rows)) {
                return response()->json(['error' => 'No valid region data rows found in CSV.'], 422);
            }

            // Sum by matching date + region across all files. A date present in
            // only one file is kept as-is (union); shared dates are added together.
            $dateGroups = [];
            foreach ($rows as $row) {
                $date = $row['reporting_date'];
                $dateGroups[$date][$row['region']] = ($dateGroups[$date][$row['region']] ?? 0) + $row['amount_spent'];
            }

            $results = [];
            foreach ($dateGroups as $date => $regionAmounts) {
                $results[$date] = RegionSpendAggregationService::storeAndAggregate('meta', $date, $regionAmounts, $project);
            }

            ActivityLogService::log(
                $user->id,
                'meta_region_report_upload',
                "Region upload {$originalName}: " . count($files) . ' file(s), ' . count($rows) . ' rows, ' . count($dateGroups) . ' date(s) auto-filled',
                'meta_ad_report',
                null,
                ['files' => $names, 'rows' => count($rows), 'dates' => array_keys($dateGroups)]
            );

            return response()->json([
                'ok' => true,
                'type' => 'region',
                'files' => count($files),
                'total_rows' => count($rows),
                'dates_processed' => count($dateGroups),
                'auto_filled' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Meta region CSV parse failed', ['file' => $originalName, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to parse region CSV: ' . $e->getMessage()], 422);
        }
    }

    private function parseRegionCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = str_getcsv($content, "\n");
        if (count($lines) < 2) {
            return [];
        }

        $header = str_getcsv($lines[0]);
        $header = array_map('trim', $header);

        $columnMap = [
            'Day'                => 'reporting_date',
            // Meta's "Ad Set view" export carries the language inside the ad-set
            // name (e.g. "ROI Both | Tamilnadu"); we resolve a language slug from it
            // below and feed that to the aggregator in place of the old Region column.
            'Ad set name'        => 'ad_set_name',
            'Amount spent (INR)' => 'amount_spent',
            // Optional — recognized but not used; export may include them so we
            // accept them silently. Falls back here if 'Day' is missing.
            'Reporting starts'   => 'reporting_starts',
            'Reporting ends'     => 'reporting_ends',
        ];

        $indices = [];
        foreach ($header as $i => $col) {
            if (isset($columnMap[$col])) {
                $indices[$columnMap[$col]] = $i;
            }
        }

        if (! isset($indices['ad_set_name'], $indices['amount_spent'])) {
            throw new \RuntimeException('Missing required columns: Ad set name, Amount spent (INR)');
        }
        if (! isset($indices['reporting_date']) && ! isset($indices['reporting_starts'])) {
            throw new \RuntimeException('Missing date column: provide either "Day" or "Reporting starts"');
        }

        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') continue;

            $cols = str_getcsv($line);
            $date = isset($indices['reporting_date'])
                ? trim($cols[$indices['reporting_date']] ?? '')
                : '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && isset($indices['reporting_starts'])) {
                $date = trim($cols[$indices['reporting_starts']] ?? '');
            }
            $adSet = trim($cols[$indices['ad_set_name']] ?? '');
            $amount = (float) str_replace([',', '₹'], '', trim($cols[$indices['amount_spent']] ?? '0'));

            if (! $date || ! $adSet || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

            // 'region' now carries a language slug (tamil/telugu/.../hindi); the
            // aggregator maps it slug->slug. Many ad sets collapse onto one language.
            $rows[] = [
                'reporting_date' => $date,
                'region' => RegionSpendAggregationService::languageFromAdSetName($adSet),
                'amount_spent' => $amount,
            ];
        }

        return $rows;
    }

    private function handleDelete(Request $request, $user): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['error' => 'No IDs provided'], 422);
        }

        $deleted = MetaAdReport::whereIn('id', $ids)->delete();

        ActivityLogService::log(
            $user->id,
            'meta_ad_report_delete',
            "Deleted {$deleted} Meta ad report rows",
            'meta_ad_report',
            null,
            ['ids' => $ids, 'deleted' => $deleted]
        );

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
