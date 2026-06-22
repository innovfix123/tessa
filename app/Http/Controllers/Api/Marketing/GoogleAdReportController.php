<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\GoogleAdReport;
use App\Models\Role;
use App\Services\ActivityLogService;
use App\Services\RegionSpendAggregationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleAdReportController extends Controller
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
        $query = GoogleAdReport::query();

        if ($project && array_key_exists($project, GoogleAdReport::PROJECTS)) {
            $query->where('project', $project);
        }
        if ($request->filled('from')) {
            $query->where('reporting_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('reporting_date', '<=', $request->input('to'));
        }
        if ($request->filled('campaign')) {
            $query->where('campaign_name', 'like', '%' . $request->input('campaign') . '%');
        }

        $reports = $query->orderByDesc('reporting_date')
            ->orderByDesc('cost')
            ->limit(500)
            ->get();

        $summary = [
            'total_spend' => $reports->sum('cost'),
            'total_purchases' => $reports->sum('purchases'),
            'total_purchase_value' => $reports->sum('purchase_value'),
            'row_count' => $reports->count(),
        ];

        $items = $reports->map(function ($r) {
            return [
                'id' => $r->id,
                'project' => $r->project,
                'campaign_name' => $r->campaign_name,
                'currency_code' => $r->currency_code,
                'cost' => round((float) $r->cost, 2),
                'avg_cpc' => $r->avg_cpc ? round((float) $r->avg_cpc, 2) : null,
                'ctr' => $r->ctr ? round((float) $r->ctr, 2) : null,
                'cpi' => $r->cpi ? round((float) $r->cpi, 2) : null,
                'cpr' => $r->cpr ? round((float) $r->cpr, 2) : null,
                'cpftd' => $r->cpftd ? round((float) $r->cpftd, 2) : null,
                'cp_d1mp' => $r->cp_d1mp ? round((float) $r->cp_d1mp, 2) : null,
                'purchases' => (int) $r->purchases,
                'cpp' => $r->cpp ? round((float) $r->cpp, 2) : null,
                'purchase_value' => round((float) $r->purchase_value, 2),
                'reporting_date' => $r->reporting_date->format('Y-m-d'),
            ];
        });

        // Date coverage per project for last 7 days. We include both per-campaign
        // uploads (google_ad_reports) AND region uploads (region_ad_spend_cache),
        // so a project shows as "uploaded" for the day either way.
        $uploadedDates = GoogleAdReport::selectRaw('project, reporting_date, COUNT(*) as row_count, SUM(cost) as day_spend')
            ->groupBy('project', 'reporting_date')
            ->orderByDesc('reporting_date')
            ->get();

        $uploadMap = [];
        foreach ($uploadedDates as $r) {
            $uploadMap[$r->project][$r->reporting_date->format('Y-m-d')] = [
                'rows'  => (int) $r->row_count,
                'spend' => (float) $r->day_spend,
            ];
        }

        $regionRows = \DB::table('region_ad_spend_cache')
            ->where('source', 'google')
            ->select('project', 'reporting_date', \DB::raw('COUNT(*) as row_count'), \DB::raw('SUM(amount) as day_spend'))
            ->groupBy('project', 'reporting_date')
            ->get();
        foreach ($regionRows as $r) {
            $key = Carbon::parse($r->reporting_date)->format('Y-m-d');
            // Region cache wins only when there's no campaign upload for that day,
            // so we don't double-count spend.
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
        foreach (GoogleAdReport::PROJECTS as $slug => $label) {
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

        // Top-level summary: when a date range / project filter is active, include
        // matching region uploads in total_spend so Anirudh's region-only uploads
        // aren't invisible.
        $regionSummaryQuery = \DB::table('region_ad_spend_cache')->where('source', 'google');
        if ($project && array_key_exists($project, GoogleAdReport::PROJECTS)) {
            $regionSummaryQuery->where('project', $project);
        }
        if ($request->filled('from')) {
            $regionSummaryQuery->where('reporting_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $regionSummaryQuery->where('reporting_date', '<=', $request->input('to'));
        }
        // Avoid double-counting: only sum region rows for (project, date) pairs
        // that have NO campaign upload.
        $campaignDates = GoogleAdReport::query();
        if ($project && array_key_exists($project, GoogleAdReport::PROJECTS)) {
            $campaignDates->where('project', $project);
        }
        $existingPairs = $campaignDates->select('project', 'reporting_date')->distinct()->get()
            ->map(fn ($r) => $r->project . '|' . $r->reporting_date->format('Y-m-d'))
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

        // Per-date region breakdown so the UI can show region-only uploads as
        // rows when there are no campaign-level rows for the date.
        $regionListQuery = \DB::table('region_ad_spend_cache')->where('source', 'google');
        if ($project && array_key_exists($project, GoogleAdReport::PROJECTS)) {
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
            'coverage' => $coverage,
            'projects' => GoogleAdReport::PROJECTS,
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
     * a region/state-level export. Returns 'region', 'campaign', or null if
     * undecidable (let the caller fall back to the explicit `type` field).
     */
    private function detectCsvType(Request $request): ?string
    {
        $file = $request->file('file');
        if (! $file) return null;

        $content = @file_get_contents($file->getRealPath());
        if ($content === false || $content === '') return null;

        if (substr($content, 0, 2) === "\xFF\xFE") {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        // Only inspect the first ~50 lines — Google Ads exports have title rows
        // before the header but never that many.
        $head = implode("\n", array_slice(explode("\n", $content), 0, 50));

        $hasState    = stripos($head, 'State (Matched)') !== false || stripos($head, "\tState\t") !== false;
        $hasCampaign = preg_match('/(^|\t|,)Campaign(\t|,|$)/m', $head) === 1;

        if ($hasState && ! $hasCampaign) return 'region';
        if ($hasCampaign) return 'campaign';
        return null;
    }

    private function handleUpload(Request $request, $user): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:20480',
            'file2' => 'nullable|file|mimes:csv,txt|max:20480',
            'project' => 'required|in:' . implode(',', array_keys(GoogleAdReport::PROJECTS)),
        ]);

        $project = $request->input('project');
        $files = array_values(array_filter([$request->file('file'), $request->file('file2')]));

        $inserted = 0;
        $skipped = 0;
        $errors = [];
        $totalRows = 0;
        $names = [];

        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $names[] = $originalName;
            $path = $file->store('google_ad_reports', 'public');

            try {
                $rows = $this->parseCsv(file_get_contents($file->getRealPath()));
            } catch (\Exception $e) {
                Storage::disk('public')->delete($path);
                Log::error('GoogleAdReport CSV parse failed', ['file' => $originalName, 'error' => $e->getMessage()]);
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
                        $row['reporting_date'],
                    ]));

                    if (GoogleAdReport::where('row_hash', $hash)->exists()) {
                        $skipped++;
                        continue;
                    }

                    GoogleAdReport::create(array_merge($row, [
                        'project' => $project,
                        'uploaded_by' => $user->id,
                        'source_file' => $originalName,
                        'row_hash' => $hash,
                    ]));
                    $inserted++;
                } catch (\Exception $e) {
                    $errors[] = $originalName . ' row ' . ($i + 2) . ': ' . $e->getMessage();
                    Log::warning('GoogleAdReport row import failed', [
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
            'google_ad_report_upload',
            'Uploaded ' . implode(' + ', $names) . ": {$inserted} inserted, {$skipped} skipped",
            'google_ad_report',
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
        // Google Ads exports can be UTF-16LE; convert to UTF-8
        if (substr($content, 0, 2) === "\xFF\xFE") {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        }
        // Strip UTF-8 BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // Normalize line endings
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = explode("\n", $content);

        // Google Ads CSV has title rows before the header.
        // Find the header row containing "Day" and "Campaign"
        $headerIdx = null;
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if (stripos($trimmed, 'Day') !== false && stripos($trimmed, 'Campaign') !== false) {
                $headerIdx = $idx;
                break;
            }
        }

        if ($headerIdx === null) {
            throw new \RuntimeException('Could not find header row with "Day" and "Campaign" columns.');
        }

        $header = str_getcsv($lines[$headerIdx], "\t");
        $header = array_map('trim', $header);

        // Map CSV column names to DB columns
        $columnMap = [
            'Day' => 'reporting_date',
            'Campaign' => 'campaign_name',
            'Currency code' => 'currency_code',
            'Cost' => 'cost',
            'Avg. CPC' => 'avg_cpc',
            'CTR' => 'ctr',
            'CPI (CE)' => 'cpi',
            'CPR' => 'cpr',
            'CPFTD' => 'cpftd',
            'CP D1MP' => 'cp_d1mp',
            'purchase' => 'purchases',
            'CPP' => 'cpp',
            'Purchase.Value' => 'purchase_value',
        ];

        $indices = [];
        foreach ($header as $i => $col) {
            if (isset($columnMap[$col])) {
                $indices[$columnMap[$col]] = $i;
            }
        }

        $required = ['campaign_name', 'reporting_date'];
        foreach ($required as $req) {
            if (! isset($indices[$req])) {
                throw new \RuntimeException("Missing required column mapping for: {$req}");
            }
        }

        $rows = [];
        for ($lineNum = $headerIdx + 1; $lineNum < count($lines); $lineNum++) {
            $line = trim($lines[$lineNum]);
            if ($line === '') {
                continue;
            }

            $cols = str_getcsv($line, "\t");

            $row = [];
            foreach ($indices as $dbCol => $csvIdx) {
                $val = isset($cols[$csvIdx]) ? trim($cols[$csvIdx]) : '';
                // Strip rupee sign and commas
                $val = str_replace(['₹', ','], '', $val);
                $val = trim($val);

                if ($dbCol === 'reporting_date') {
                    $row[$dbCol] = $val ?: null;
                } elseif (in_array($dbCol, ['campaign_name', 'currency_code'])) {
                    $row[$dbCol] = $val;
                } elseif ($dbCol === 'purchases') {
                    $row[$dbCol] = $val !== '' ? (int) floatval($val) : 0;
                } elseif ($dbCol === 'ctr') {
                    // CTR comes as "2.13%" — strip the percent sign
                    $row[$dbCol] = $val !== '' ? (float) str_replace('%', '', $val) : null;
                } else {
                    $row[$dbCol] = $val !== '' ? (float) $val : null;
                }
            }

            if (empty($row['campaign_name']) || empty($row['reporting_date'])) {
                continue;
            }

            // Validate date format
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['reporting_date'])) {
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
            'project' => 'required|in:' . implode(',', array_keys(GoogleAdReport::PROJECTS)),
        ]);

        $project = $request->input('project');
        $files = array_values(array_filter([$request->file('file'), $request->file('file2')]));
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

            // Sum by matching date + state across all files. A date present in
            // only one file is kept as-is (union); shared dates are added together.
            $dateGroups = [];
            foreach ($rows as $row) {
                $date = $row['reporting_date'];
                $dateGroups[$date][$row['state']] = ($dateGroups[$date][$row['state']] ?? 0) + $row['cost'];
            }

            $results = [];
            foreach ($dateGroups as $date => $regionAmounts) {
                $results[$date] = RegionSpendAggregationService::storeAndAggregate('google', $date, $regionAmounts, $project);
            }

            ActivityLogService::log(
                $user->id,
                'google_region_report_upload',
                "Region upload {$originalName}: " . count($files) . ' file(s), ' . count($rows) . ' rows, ' . count($dateGroups) . ' date(s) auto-filled',
                'google_ad_report',
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
            Log::error('Google region CSV parse failed', ['file' => $originalName, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to parse region CSV: ' . $e->getMessage()], 422);
        }
    }

    private function parseRegionCsv(string $content): array
    {
        // Google Ads region exports are UTF-16LE
        if (substr($content, 0, 2) === "\xFF\xFE") {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16LE');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = explode("\n", $content);

        // Parse date from the title area (second non-empty line) as a fallback
        // for older exports that didn't include a per-row "Day" column.
        // Format: "13 April 2026 - 13 April 2026"
        $titleDate = null;
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;
            if (preg_match('/(\d{1,2}\s+\w+\s+\d{4})/', $trimmed, $m)) {
                try {
                    $titleDate = Carbon::createFromFormat('j F Y', $m[1])->format('Y-m-d');
                    break;
                } catch (\Exception $e) {
                    // not a date line, keep looking
                }
            }
        }

        // Find the header row. These exports come two ways: by region (a
        // "State (Matched)" column) or by ad group (an "Ad group" column whose name
        // carries the language, e.g. "Ai | Tamil"). Either one, plus Cost or Day.
        $headerIdx = null;
        foreach ($lines as $idx => $line) {
            $trimmed = trim($line);
            if ((stripos($trimmed, 'State') !== false || stripos($trimmed, 'Ad group') !== false)
                && (stripos($trimmed, 'Cost') !== false || stripos($trimmed, 'Day') !== false)
            ) {
                $headerIdx = $idx;
                break;
            }
        }

        if ($headerIdx === null) {
            throw new \RuntimeException('Could not find header row with "State (Matched)" or "Ad group", plus "Cost"/"Day" columns.');
        }

        $header = str_getcsv($lines[$headerIdx], "\t");
        $header = array_map('trim', $header);

        // Column → canonical-key map. Only state/cost are required to aggregate
        // spend; the rest are recognized so the parser doesn't choke on them.
        $columnMap = [
            'Day'             => 'reporting_date',
            'State (Matched)' => 'state',
            'Ad group'        => 'ad_group',
            'Currency code'   => 'currency_code',
            'Cost'            => 'cost',
            'Impr.'           => 'impressions',
            'Clicks'          => 'clicks',
            'CE install'      => 'ce_install',
            'Registration'    => 'registration',
            'FTD'             => 'ftd',
            'D1MP'            => 'd1mp',
            'purchase'        => 'purchase',
            'Purchase.Value'  => 'purchase_value',
        ];

        $indices = [];
        foreach ($header as $i => $col) {
            if (isset($columnMap[$col])) {
                $indices[$columnMap[$col]] = $i;
            }
        }

        if (! isset($indices['cost']) || (! isset($indices['state']) && ! isset($indices['ad_group']))) {
            throw new \RuntimeException('Missing required columns: "State (Matched)" or "Ad group", plus "Cost".');
        }
        if (! isset($indices['reporting_date']) && ! $titleDate) {
            throw new \RuntimeException('Missing date: provide a "Day" column or include a date in the title line.');
        }

        $rows = [];
        for ($lineNum = $headerIdx + 1; $lineNum < count($lines); $lineNum++) {
            // Parse the RAW line (don't trim it first) so a row with an empty leading
            // "Day" cell — e.g. the export's "Total: …" summary — keeps its columns
            // aligned instead of shifting left.
            if (trim($lines[$lineNum]) === '') continue;

            $cols = str_getcsv($lines[$lineNum], "\t");
            $cost = (float) str_replace(['₹', ',', ' '], '', trim($cols[$indices['cost']] ?? '0'));

            // Grouping key: a real state, else the language parsed from the ad-group
            // name ("Ai | Tamil" -> tamil). storeAndAggregate maps both forms. The
            // export's "Total: …" summary row is skipped either way.
            $rawKey = isset($indices['state'])
                ? trim($cols[$indices['state']] ?? '')
                : trim($cols[$indices['ad_group']] ?? '');
            if ($rawKey === '' || stripos($rawKey, 'total') === 0) continue;

            $region = isset($indices['state'])
                ? $rawKey
                : RegionSpendAggregationService::languageFromAdSetName($rawKey);

            $rowDate = isset($indices['reporting_date'])
                ? trim($cols[$indices['reporting_date']] ?? '')
                : '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rowDate)) {
                $rowDate = $titleDate;
            }
            if (! $rowDate) continue;

            $rows[] = [
                'reporting_date' => $rowDate,
                'state' => $region,
                'cost' => $cost,
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

        $deleted = GoogleAdReport::whereIn('id', $ids)->delete();

        ActivityLogService::log(
            $user->id,
            'google_ad_report_delete',
            "Deleted {$deleted} Google ad report rows",
            'google_ad_report',
            null,
            ['ids' => $ids, 'deleted' => $deleted]
        );

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }
}
