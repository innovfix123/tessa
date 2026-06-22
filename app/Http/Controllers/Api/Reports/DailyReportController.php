<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\CreativeUpload;
use App\Models\DailyReport;
use App\Models\KpiDefinition;
use App\Models\LeaveRequest;
use App\Models\GoogleAdReport;
use App\Models\ManagerNotification;
use App\Models\MetaAdReport;
use App\Models\User;
use App\Helpers\DateHelper;
use App\Services\ActivityLogService;
use App\Services\HimaAnalyticsService;
use App\Services\ProjectRoleService;
use App\Support\DailyReportsAccess;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) ($request->query('user_id') ?? $request->query('person_id') ?? 0);
        $reportDate = trim($request->query('report_date', ''));
        $weekKey = trim($request->query('week_key', ''));

        Log::debug('DailyReportController::index', [
            'user_id' => $userId,
            'report_date' => $reportDate,
            'week_key' => $weekKey,
            'request_user_id' => $request->user()->id,
        ]);

        if ($userId <= 0) {
            Log::debug('DailyReportController::index validation failed', ['error' => 'user_id is required']);

            return response()->json(['error' => 'user_id is required'], 422);
        }

        if (! ProjectRoleService::canAccessUserDailyReport($request->user(), $userId)) {
            Log::warning('DailyReportController::index access denied', [
                'user_role' => $request->user()->role,
                'target_user_id' => $userId,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($reportDate !== '') {
            $this->requireDate($reportDate, 'report_date');
            $reportWeekKey = DateHelper::parse($reportDate)->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $fieldsMeta = $this->getFieldsForUser($userId, $reportWeekKey, $request->user()->id);
            $rows = DailyReport::where('user_id', $userId)
                ->where('report_date', $reportDate)
                ->get();
            $entries = $rows->pluck('value', 'field_key')->toArray();
            $choiceEntries = $rows->pluck('choice_value', 'field_key')->filter(fn ($v) => $v !== null && $v !== '')->toArray();

            return response()->json([
                'ok' => true,
                'userId' => $userId,
                'reportDate' => $reportDate,
                'entries' => $entries,
                'choiceEntries' => $choiceEntries,
                'editable' => $this->isEditableDate($reportDate),
                'fields' => $fieldsMeta['fields'],
                'aggregation' => $fieldsMeta['aggregation'],
            ]);
        }

        if ($weekKey === '') {
            Log::debug('DailyReportController::index validation failed', ['error' => 'Either report_date or week_key is required']);

            return response()->json(['error' => 'Either report_date or week_key is required'], 422);
        }
        $weekKey = $this->requireDate($weekKey, 'week_key');
        $fieldsMeta = $this->getFieldsForUser($userId, $weekKey, $request->user()->id);

        $this->refreshHimaPaidUsers($userId, $weekKey);

        $start = DateHelper::parse($weekKey);
        $end = $start->copy()->addDays(6);
        $rows = DailyReport::where('user_id', $userId)
            ->whereBetween('report_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->orderBy('report_date')
            ->get()
            ->map(fn ($r) => [
                'reportDate' => $r->report_date->format('Y-m-d'),
                'fieldKey' => $r->field_key,
                'value' => $r->value ?? '',
                'choiceValue' => $r->choice_value,
            ])
            ->toArray();

        $weekData = $this->buildWeekData($weekKey, $rows, $fieldsMeta['aggregation']);

        // Build Meta Ad hints for performance marketing KPIs
        $metaHints = $this->buildMetaAdHints($userId, $fieldsMeta['fields'], $weekKey);

        return response()->json([
            'ok' => true,
            'userId' => $userId,
            'weekKey' => $weekKey,
            'days' => $weekData['days'],
            'summary' => $weekData['summary'],
            'fields' => $fieldsMeta['fields'],
            'aggregation' => $fieldsMeta['aggregation'],
            'metaHints' => $metaHints,
        ]);
    }

    /**
     * Download a custom date-range Excel (.xlsx) of daily-report entries for the
     * people the requester can see. Gated to a tiny allow-list (Finance/payroll)
     * via config('daily_reports_access.export_user_ids') — separate from tab
     * access, since being able to view the tab does not grant export.
     *
     * Output is one tall, pivot-friendly sheet: Person | Date | Group | Field | Value
     * (one row per filled entry; empty cells are skipped).
     */
    public function export(Request $request)
    {
        $user = $request->user();

        $exportIds = array_map('intval', (array) config('daily_reports_access.export_user_ids', []));
        if (! in_array((int) $user->id, $exportIds, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $from = $this->requireDate($request->query('from', ''), 'from');
        $to = $this->requireDate($request->query('to', ''), 'to');
        if ($from > $to) {
            return response()->json(['error' => 'from must be on or before to'], 422);
        }
        if (abs(DateHelper::parse($from)->diffInDays(DateHelper::parse($to))) > 366) {
            return response()->json(['error' => 'Date range too large (max 366 days)'], 422);
        }

        // Whom to export: the ids the tab renders (client-supplied), each
        // re-authorized server-side. Never trust the client list as-is. Falls
        // back to the requester's own report when nothing is passed.
        $requestedIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->query('user_ids', ''))
        )));
        if (empty($requestedIds)) {
            $requestedIds = [(int) $user->id];
        }
        $targetIds = array_values(array_filter(
            $requestedIds,
            fn ($id) => ProjectRoleService::canAccessUserDailyReport($user, $id)
        ));
        if (empty($targetIds)) {
            return response()->json(['error' => 'No accessible users in selection'], 403);
        }

        $names = User::whereIn('id', $targetIds)->pluck('name', 'id');

        // Per-user field metadata (label/group/sort). withTrashed so a soft-deleted
        // KPI definition still resolves a human label; raw field_key as last resort.
        $fieldMeta = [];
        foreach ($targetIds as $uid) {
            $defs = KpiDefinition::withTrashed()
                ->where('user_id', $uid)
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->get();
            foreach ($defs as $d) {
                $fieldMeta[$uid][$d->field_key] = [
                    'label' => $d->field_label ?: $d->field_key,
                    'group' => $d->group_name ?: 'Metrics',
                    'sort' => (int) ($d->sort_order ?? 0),
                ];
            }
        }

        $rows = DailyReport::whereIn('user_id', $targetIds)
            ->whereBetween('report_date', [$from, $to])
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $value = ($r->value !== null && $r->value !== '')
                ? $r->value
                : (string) ($r->choice_value ?? '');
            if (trim((string) $value) === '') {
                continue; // skip empty cells
            }
            $meta = $fieldMeta[$r->user_id][$r->field_key] ?? [
                'label' => $r->field_key,
                'group' => 'Metrics',
                'sort' => 9999,
            ];
            $data[] = [
                'name' => $names[$r->user_id] ?? (string) $r->user_id,
                'date' => $r->report_date->format('Y-m-d'),
                'group' => $meta['group'],
                'field' => $meta['label'],
                'sort' => $meta['sort'],
                'value' => $value,
            ];
        }

        // Stable order: person → date → group → field sort_order → field label.
        usort($data, function ($a, $b) {
            return [$a['name'], $a['date'], $a['group'], $a['sort'], $a['field']]
                <=> [$b['name'], $b['date'], $b['group'], $b['sort'], $b['field']];
        });

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daily Reports');

        $headers = ['Person', 'Date', 'Group', 'Field', 'Value'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:E1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF1F2937');
        $sheet->getStyle('A1:E1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A2');

        $rowNum = 2;
        foreach ($data as $d) {
            $sheet->setCellValue('A' . $rowNum, $d['name']);
            $sheet->setCellValue('B' . $rowNum, $d['date']);
            $sheet->setCellValue('C' . $rowNum, $d['group']);
            $sheet->setCellValue('D' . $rowNum, $d['field']);
            // Explicit string so Excel never reinterprets numeric KPI values
            // (amounts, long ids) as numbers / scientific notation.
            $sheet->setCellValueExplicit('E' . $rowNum, (string) $d['value'], DataType::TYPE_STRING);
            $rowNum++;
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'daily-reports-' . $from . '_to_' . $to . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function getFieldsForUser(int $userId, ?string $weekKey = null, ?int $viewerId = null): array
    {
        $query = $weekKey
            ? KpiDefinition::withTrashed()->visibleForWeek($weekKey)->where('user_id', $userId)
            : KpiDefinition::where('user_id', $userId);

        $definitions = $query
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->where('group_name', '!=', 'Network Leverage')
            ->orderByRaw("CASE WHEN group_name = 'Bengali' THEN 1 ELSE 0 END")
            ->orderBy('group_name')
            ->orderBy('sort_order')
            ->get();

        // Per-field manager visibility: when a routed manager (see config) views
        // someone else's report, hide fields routed to a DIFFERENT manager. The
        // owner, admins, and any non-routed viewer always see every field. Users
        // with no config entry are unaffected.
        $fieldManagers = config('daily_report_field_visibility.' . $userId, []);
        $routedManagerIds = array_values(array_unique($fieldManagers));
        $applyFieldVisibility = $viewerId !== null
            && $viewerId !== $userId
            && in_array($viewerId, $routedManagerIds, true);

        $fields = [];
        $aggregation = [];
        // Check which fields are team-aggregated (manager with subordinates sharing same upload/textarea KPI)
        $subIds = User::where('reporting_manager_id', $userId)->pluck('id')->toArray();
        // Managers who opted out of narrative roll-up never show the team-total
        // hint/count for textarea fields — their tab stays strictly per-person.
        $noTextareaAgg = in_array($userId, config('daily_report_aggregation.no_textarea_aggregation_user_ids', []), true);
        foreach ($definitions as $d) {
            if ($applyFieldVisibility
                && isset($fieldManagers[$d->field_key])
                && (int) $fieldManagers[$d->field_key] !== $viewerId) {
                continue; // this field belongs to a different manager's tab
            }
            $isTeamTotal = false;
            $aggregatable = $d->input_type === 'upload'
                || ($d->input_type === 'textarea' && ! $noTextareaAgg);
            if (! empty($subIds) && $aggregatable) {
                $isTeamTotal = KpiDefinition::whereIn('user_id', $subIds)
                    ->where('field_key', $d->field_key)
                    ->whereIn('input_type', ['upload', 'textarea'])
                    ->whereNull('deleted_at')
                    ->exists();
            }
            $fields[] = [
                'key' => $d->field_key,
                'label' => $d->field_label,
                'group' => $d->group_name ?: 'Metrics',
                'auto_sync' => (bool) $d->auto_sync,
                'input_type' => $d->input_type ?? 'text',
                'upload_accept' => $d->upload_accept,
                'upload_max_mb' => $d->upload_max_mb,
                'choices' => $d->choices ?: null,
                'is_team_total' => $isTeamTotal,
            ];
            if ($d->aggregation) {
                $aggregation[$d->field_key] = $d->aggregation;
            }
        }

        return ['fields' => $fields, 'aggregation' => $aggregation];
    }

    public function store(Request $request): JsonResponse
    {
        $userRole = $request->user()->role;
        if (! ProjectRoleService::canEditDailyReport($userRole)) {
            Log::warning('DailyReportController::store access denied', [
                'user_role' => $userRole,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        $action = $request->input('action', '');
        if ($action === 'save_choice') {
            return $this->saveChoice($request);
        }
        if ($action !== 'save_entry') {
            Log::debug('DailyReportController::store unknown action', ['action' => $action]);

            return response()->json(['error' => 'Unknown action'], 404);
        }

        $userId = (int) ($request->input('userId') ?? $request->input('personId') ?? 0);
        $reportDate = $this->requireDate(trim($request->input('reportDate', '')), 'reportDate');
        $fieldKey = $this->normalizeFieldKey(trim($request->input('fieldKey', '')), $userId);
        $value = $request->input('value', '');

        if ($userId <= 0) {
            Log::debug('DailyReportController::store validation failed', ['error' => 'userId is required']);

            return response()->json(['error' => 'userId is required'], 422);
        }

        if (! ProjectRoleService::canAccessUserDailyReport($request->user(), $userId)) {
            Log::warning('DailyReportController::store access denied', [
                'user_role' => $userRole,
                'target_user_id' => $userId,
                'request_user_id' => $request->user()->id,
            ]);

            return response()->json(['error' => 'Forbidden'], 403);
        }

        $def = KpiDefinition::where('user_id', $userId)->where('field_key', $fieldKey)->first();
        if ($def && ($def->input_type ?? 'text') === 'upload') {
            return response()->json(['error' => 'This field is managed by file uploads'], 422);
        }

        if (! $this->isEditableDate($reportDate)) {
            Log::warning('DailyReportController::store date not editable', [
                'report_date' => $reportDate,
                'user_id' => $userId,
            ]);

            return response()->json(['error' => 'Only today and previous day are editable'], 403);
        }

        $inputType = $def->input_type ?? 'text';
        // 'status' is an enum; 'text_multiline' and 'textarea' are free-form prose —
        // all must keep their value verbatim. The strip below only removes currency/
        // percent/comma formatting from numeric KPI inputs (input_type 'text'), and
        // would otherwise delete every space and newline from a write-up (e.g. turn
        // "HR Operations" into "HROperations").
        if ($inputType !== 'status' && $inputType !== 'text_multiline' && $inputType !== 'textarea') {
            $value = preg_replace('/[₹%,\s]/', '', $value);
        }

        DailyReport::updateOrCreate(
            [
                'user_id' => $userId,
                'report_date' => $reportDate,
                'field_key' => $fieldKey,
            ],
            [
                'value' => $value,
                'updated_by' => $request->user()->id,
            ]
        );

        if (HimaAnalyticsService::isCpaTriggerField($userId, $fieldKey)) {
            HimaAnalyticsService::recalculateCpa($reportDate, $request->user()->id);
        }

        Log::info('DailyReportController::store entry saved', [
            'user_id' => $userId,
            'report_date' => $reportDate,
            'field_key' => $fieldKey,
            'updated_by' => $request->user()->id,
        ]);

        $actor = $request->user();
        ActivityLogService::log(
            $actor->id,
            'daily_report_saved',
            "{$actor->name} updated daily report: {$fieldKey} = {$value} for {$reportDate}",
            'daily_report',
            null,
            ['field_key' => $fieldKey, 'value' => $value, 'report_date' => $reportDate, 'target_user_id' => $userId]
        );

        return response()->json(['ok' => true]);
    }

    private function requireDate(string $value, string $fieldName): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            Log::debug('DailyReportController::requireDate validation failed', [
                'field_name' => $fieldName,
                'value' => $value,
            ]);
            throw new HttpResponseException(
                response()->json(['error' => "{$fieldName} must be YYYY-MM-DD"], 422)
            );
        }

        return trim($value);
    }

    private function normalizeFieldKey(string $fieldKey, int $userId): string
    {
        $allowed = $this->getAllowedFieldKeys($userId);
        $normalized = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $fieldKey));
        if (! in_array($normalized, $allowed, true)) {
            Log::debug('DailyReportController::normalizeFieldKey validation failed', [
                'field_key' => $fieldKey,
                'normalized' => $normalized,
                'user_id' => $userId,
            ]);
            throw new HttpResponseException(
                response()->json(['error' => 'Invalid field key'], 422)
            );
        }

        return $normalized;
    }

    private function getAllowedFieldKeys(int $userId): array
    {
        $meta = $this->getFieldsForUser($userId);

        return array_column($meta['fields'], 'key');
    }

    private const ANIRUDH_USER_ID = 11;

    private const PAID_USERS_LANGUAGE_MAP = [
        'Tamil' => 'tamil_total_paid_registered_users',
        'Telugu' => 'telugu_total_paid_registered_users',
        'Kannada' => 'kannada_total_paid_registered_users',
        'Malayalam' => 'malayalam_total_paid_registered_users',
        'Bengali' => 'bengali_total_paid_registered_users',
        'Hindi' => 'hindi_total_paid_registered_users',
    ];

    private function refreshHimaPaidUsers(int $userId, string $weekKey): void
    {
        if ($userId !== self::ANIRUDH_USER_ID) {
            return;
        }

        $today = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $yesterday = Carbon::now('Asia/Kolkata')->subDay()->format('Y-m-d');

        $start = DateHelper::parse($weekKey)->format('Y-m-d');
        $end = DateHelper::parse($weekKey)->addDays(6)->format('Y-m-d');

        $datesToRefresh = array_filter([$today, $yesterday], fn ($d) => $d >= $start && $d <= $end);
        if (empty($datesToRefresh)) {
            return;
        }

        $service = app(HimaAnalyticsService::class);

        foreach ($datesToRefresh as $date) {
            $data = $service->getPaidRegisteredByLanguage($date);
            if (! $data || ! ($data['success'] ?? false)) {
                continue;
            }

            $byLanguage = [];
            foreach ($data['data'] ?? [] as $entry) {
                $byLanguage[$entry['language']] = $entry;
            }

            foreach (self::PAID_USERS_LANGUAGE_MAP as $langName => $fieldKey) {
                $value = $byLanguage[$langName]['total_paid_registered_users'] ?? null;
                if ($value === null) {
                    continue;
                }

                DailyReport::updateOrCreate(
                    ['user_id' => self::ANIRUDH_USER_ID, 'report_date' => $date, 'field_key' => $fieldKey],
                    ['value' => (string) $value, 'updated_by' => 1]
                );
            }

            HimaAnalyticsService::recalculateCpa($date);
        }
    }

    private function isEditableDate(string $reportDate): bool
    {
        return true;
    }

    private function buildWeekData(string $weekKey, array $rows, array $aggregationMap = []): array
    {
        $agg = $aggregationMap;
        $byDate = [];
        $summary = array_fill_keys(array_keys($agg), '');
        $sumState = [];
        $avgState = [];
        $latestState = [];

        $choicesByDate = [];

        foreach ($rows as $row) {
            $date = $row['reportDate'] ?? '';
            $fieldKey = $row['fieldKey'] ?? '';
            $value = $row['value'] ?? '';
            $choiceValue = $row['choiceValue'] ?? null;
            if ($date === '' || $fieldKey === '') {
                continue;
            }

            $byDate[$date] = $byDate[$date] ?? [];
            $byDate[$date][$fieldKey] = $value;

            if ($choiceValue !== null && $choiceValue !== '') {
                $choicesByDate[$date] = $choicesByDate[$date] ?? [];
                $choicesByDate[$date][$fieldKey] = $choiceValue;
            }

            $aggregation = $agg[$fieldKey] ?? null;
            if ($aggregation === null || $value === '') {
                continue;
            }

            $numericValue = preg_replace('/[₹%,\s]/', '', $value);

            if ($aggregation === 'sum' && is_numeric($numericValue)) {
                $sumState[$fieldKey] = ($sumState[$fieldKey] ?? 0.0) + (float) $numericValue;
            } elseif ($aggregation === 'avg' && is_numeric($numericValue)) {
                $avgState[$fieldKey] = $avgState[$fieldKey] ?? ['sum' => 0.0, 'count' => 0];
                $avgState[$fieldKey]['sum'] += (float) $numericValue;
                $avgState[$fieldKey]['count']++;
            } elseif ($aggregation === 'latest') {
                if (! isset($latestState[$fieldKey]) || $date > $latestState[$fieldKey]['date']) {
                    $latestState[$fieldKey] = ['date' => $date, 'value' => $value];
                }
            }
        }

        foreach ($sumState as $k => $v) {
            $summary[$k] = (string) ($v + 0);
        }
        foreach ($avgState as $k => $s) {
            if ($s['count'] > 0) {
                $summary[$k] = (string) round($s['sum'] / $s['count'], 2);
            }
        }
        foreach ($latestState as $k => $s) {
            $summary[$k] = $s['value'];
        }

        $start = DateHelper::parse($weekKey);
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i)->format('Y-m-d');
            $days[] = [
                'reportDate' => $d,
                'entries' => $byDate[$d] ?? [],
                'choices' => $choicesByDate[$d] ?? [],
            ];
        }

        return ['days' => $days, 'summary' => $summary];
    }

    /**
     * Build Meta Ad data hints for performance marketing KPI fields.
     * Returns: { field_key: { "2026-03-24": "hint text", ... } }
     */
    private function buildMetaAdHints(int $userId, array $fields, string $weekKey): array
    {
        // Map field_key patterns to Meta data queries
        // Anirudh (Hima language-wise): tamil_daily_ad_spend, tamil_cpa, telugu_daily_ad_spend, etc.
        // Nandha (project-level): thedal_daily_ad_spend, thedal_new_installs, sudar_daily_ad_spend, etc.

        $fieldKeys = array_column($fields, 'key');

        // Detect which languages/projects are relevant
        $langMap = [
            'tamil' => 'Tamil',
            'telugu' => 'Telugu',
            'kannada' => 'Kannada',
            'malayalam' => 'Malayalam',
            'bengali' => 'Bangla',
        ];
        $projectMap = [
            'thedal' => 'thedal',
            'sudar' => 'sudar',
            'hima' => 'hima',
        ];

        $needed = []; // field_key => [type, project, language_filter]
        foreach ($fieldKeys as $fk) {
            // Language-level fields (Anirudh): tamil_daily_ad_spend, tamil_cpa
            foreach ($langMap as $prefix => $campaignLang) {
                if (str_starts_with($fk, $prefix . '_')) {
                    $suffix = substr($fk, strlen($prefix) + 1);
                    $needed[$fk] = ['type' => $suffix, 'project' => 'hima', 'lang' => $campaignLang];
                    break;
                }
            }
            // Project-level fields (Nandha): thedal_daily_ad_spend, thedal_new_installs, thedal_cpa, thedal_cpp
            foreach ($projectMap as $prefix => $proj) {
                if (str_starts_with($fk, $prefix . '_') && ! isset($needed[$fk])) {
                    $suffix = substr($fk, strlen($prefix) + 1);
                    $needed[$fk] = ['type' => $suffix, 'project' => $proj, 'lang' => null];
                    break;
                }
            }
        }

        if (empty($needed)) {
            return [];
        }

        // Fetch Meta + Google Ads data for this week
        $start = DateHelper::parse($weekKey);
        $end = $start->copy()->addDays(6);

        $metaRows = MetaAdReport::whereBetween('reporting_starts', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get();

        $googleRows = GoogleAdReport::whereBetween('reporting_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get();

        if ($metaRows->isEmpty() && $googleRows->isEmpty()) {
            return [];
        }

        // Language mapping for Google Ads campaign name matching
        $googleLangMap = [
            'Tamil' => 'Tamil',
            'Telugu' => 'Telugu',
            'Kannada' => 'Kannada',
            'Malayalam' => 'Malayalam',
            'Bangla' => 'Bengali',   // Meta uses "Bangla" but Google uses "Bengali"
        ];

        $hints = [];

        foreach ($needed as $fieldKey => $config) {
            $type = $config['type'];
            $project = $config['project'];
            $lang = $config['lang'];

            // --- Meta Ads data ---
            $metaFiltered = $metaRows->where('project', $project);
            if ($lang) {
                $metaFiltered = $metaFiltered->filter(function ($r) use ($lang) {
                    return str_contains($r->campaign_name, $lang);
                });
            }
            $metaByDate = $metaFiltered->groupBy(fn ($r) => $r->reporting_starts->format('Y-m-d'));

            // --- Google Ads data ---
            $googleFiltered = $googleRows->where('project', $project);
            if ($lang) {
                $googleSearchLang = $googleLangMap[$lang] ?? $lang;
                $googleFiltered = $googleFiltered->filter(function ($r) use ($googleSearchLang, $lang) {
                    return str_contains($r->campaign_name, $googleSearchLang) || str_contains($r->campaign_name, $lang);
                });
            }
            $googleByDate = $googleFiltered->groupBy(fn ($r) => $r->reporting_date->format('Y-m-d'));

            // Collect all dates from both sources
            $allDates = $metaByDate->keys()->merge($googleByDate->keys())->unique();

            foreach ($allDates as $date) {
                $metaDateRows = $metaByDate[$date] ?? collect();
                $googleDateRows = $googleByDate[$date] ?? collect();

                $metaSpend = $metaDateRows->sum('amount_spent');
                $metaInstalls = $metaDateRows->sum('app_installs');
                $metaResults = $metaDateRows->sum('results');
                $metaPurchases = $metaDateRows->sum('new_user_first_purchase');

                $googleSpend = (float) $googleDateRows->sum('cost');
                // Derive Google installs from cost / cpi where cpi > 0
                $googleInstalls = 0;
                foreach ($googleDateRows as $gr) {
                    if ($gr->cpi > 0) {
                        $googleInstalls += (int) round($gr->cost / $gr->cpi);
                    }
                }
                $googlePurchases = $googleDateRows->sum('purchases');

                $totalSpend = $metaSpend + $googleSpend;
                $totalInstalls = $metaInstalls + $googleInstalls;
                $totalResults = $metaResults;
                $totalPurchases = $metaPurchases + $googlePurchases;

                $hint = '';
                $sources = [];
                if ($metaSpend > 0 || ! $metaDateRows->isEmpty()) {
                    $sources[] = 'Meta';
                }
                if ($googleSpend > 0 || ! $googleDateRows->isEmpty()) {
                    $sources[] = 'Google';
                }
                $srcLabel = implode(' + ', $sources);

                switch ($type) {
                    case 'daily_ad_spend':
                        $gst = $totalSpend * 0.18;
                        $hint = '₹' . number_format($totalSpend, 0) . ' (with GST: ₹' . number_format($totalSpend + $gst, 0) . ')';
                        if (count($sources) > 1) {
                            $hint .= ' [' . $srcLabel . ']';
                        }
                        break;
                    case 'new_installs':
                        $hint = number_format($totalInstalls, 0) . ' installs';
                        if (count($sources) > 1) {
                            $hint .= ' [' . $srcLabel . ']';
                        }
                        break;
                    case 'registrations_from_paid':
                        $hint = number_format($totalResults, 0) . ' results';
                        break;
                    case 'cpa':
                        $spendWithGst = $totalSpend * 1.18;
                        $cpa = $totalInstalls > 0 ? $spendWithGst / $totalInstalls : 0;
                        $hint = $totalInstalls > 0
                            ? '₹' . number_format($cpa, 2) . ' (' . number_format($totalInstalls, 0) . ' installs, incl GST)'
                            : 'No installs';
                        if ($totalInstalls > 0 && count($sources) > 1) {
                            $hint .= ' [' . $srcLabel . ']';
                        }
                        break;
                    case 'cpp':
                        $spendWithGst = $totalSpend * 1.18;
                        $cpp = $totalPurchases > 0 ? $spendWithGst / $totalPurchases : 0;
                        $hint = $totalPurchases > 0
                            ? '₹' . number_format($cpp, 2) . ' (' . number_format($totalPurchases, 0) . ' purchases, incl GST)'
                            : 'No purchases';
                        if ($totalPurchases > 0 && count($sources) > 1) {
                            $hint .= ' [' . $srcLabel . ']';
                        }
                        break;
                }

                if ($hint) {
                    $hints[$fieldKey][$date] = $hint;
                }
            }
        }

        return $hints;
    }

    /**
     * Save a choice answer (radio selection) for a daily report field.
     * Stored on the same daily_reports row as the regular value so upload
     * fields (whose `value` is auto-synced from CreativeUploads) can still
     * carry a sender/receiver tag. Owner-only — managers reviewing someone
     * else's report cannot change the selection.
     */
    private function saveChoice(Request $request): JsonResponse
    {
        $userId = (int) ($request->input('userId') ?? 0);
        $reportDate = $this->requireDate(trim($request->input('reportDate', '')), 'reportDate');
        $fieldKey = $this->normalizeFieldKey(trim($request->input('fieldKey', '')), $userId);
        $choiceValue = trim((string) $request->input('choiceValue', ''));

        if ($userId <= 0) {
            return response()->json(['error' => 'userId is required'], 422);
        }

        if ($userId !== $request->user()->id) {
            return response()->json(['error' => 'Only the owner can set this'], 403);
        }

        if (! $this->isEditableDate($reportDate)) {
            return response()->json(['error' => 'Only today and previous day are editable'], 403);
        }

        $def = KpiDefinition::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->first();
        $choices = is_array($def?->choices) ? $def->choices : [];
        if (empty($choices)) {
            return response()->json(['error' => 'Field does not support choices'], 422);
        }

        $allowed = array_column($choices, 'value');
        if ($choiceValue !== '' && ! in_array($choiceValue, $allowed, true)) {
            return response()->json(['error' => 'Invalid choice'], 422);
        }

        $row = DailyReport::firstOrNew([
            'user_id' => $userId,
            'report_date' => $reportDate,
            'field_key' => $fieldKey,
        ]);
        $row->choice_value = $choiceValue === '' ? null : $choiceValue;
        $row->updated_by = $request->user()->id;
        $row->save();

        $this->notifyManagerOfChoice($request->user(), $def, $reportDate, $row->choice_value);

        return response()->json(['ok' => true, 'choice_value' => $row->choice_value]);
    }

    private const SENDER_FIELD = 'ai_videos_generated';
    private const RECEIVER_FIELD = 'videos_delivered';
    private const KRISHNAN_USER_ID = 20;
    private const ANAS_USER_ID = 18;

    /**
     * Post (or refresh) a one-line update on Krishnan's dashboard when a team
     * member picks a sender choice or Anas picks a receiver choice. Resubmits
     * on the same (field, date) update the existing row — and clear any prior
     * dismissal so Krishnan sees the change. Same upsert + resurface pattern
     * as ChecklistController::clearUpdates and its toggle counterpart.
     */
    private function notifyManagerOfChoice(User $user, KpiDefinition $def, string $reportDate, ?string $choiceValue): void
    {
        if ($choiceValue === null) {
            // Clearing the choice removes the notification entirely so Krishnan
            // isn't left with a stale "Disha: sent to Anas" after she unselects.
            ManagerNotification::where('manager_id', self::KRISHNAN_USER_ID)
                ->where('team_member_id', $user->id)
                ->where('source', 'daily_report_choice')
                ->where('source_ref', $def->field_key . ':' . $reportDate)
                ->delete();
            return;
        }

        $isSender = $def->field_key === self::SENDER_FIELD
            && (int) $user->reporting_manager_id === self::KRISHNAN_USER_ID;
        $isReceiver = $def->field_key === self::RECEIVER_FIELD
            && $user->id === self::ANAS_USER_ID;

        if (! $isSender && ! $isReceiver) {
            return;
        }

        $choices = is_array($def->choices) ? $def->choices : [];
        $label = collect($choices)->firstWhere('value', $choiceValue)['label'] ?? $choiceValue;

        if ($isSender) {
            if ($choiceValue === 'sent_to_anas') {
                $count = CreativeUpload::where('user_id', $user->id)
                    ->where('field_key', $def->field_key)
                    ->where('report_date', $reportDate)
                    ->count();
                $noun = ($def->input_type === 'textarea')
                    ? ($count === 1 ? 'script' : 'scripts')
                    : ($count === 1 ? 'video' : 'videos');
                $message = $count > 0
                    ? "{$user->name}: sent {$count} {$noun} to Anas"
                    : "{$user->name}: marked sent to Anas (no uploads yet)";
            } else {
                $message = "{$user->name}: did not send to Anas today";
            }
        } else {
            // Anas's three-way choice — the label already reads naturally.
            $message = "{$user->name}: {$label}";
        }

        ManagerNotification::updateOrCreate(
            [
                'manager_id' => self::KRISHNAN_USER_ID,
                'team_member_id' => $user->id,
                'source' => 'daily_report_choice',
                'source_ref' => $def->field_key . ':' . $reportDate,
            ],
            [
                'message' => $message,
                'dismissed_at' => null,
            ]
        );
    }

    public function pendingDays(Request $request): JsonResponse
    {
        $user = $request->user();

        // Daily Reports rollback (2026-06-18): users outside the allow-list no
        // longer have a daily report to fill, so never surface a "pending
        // report" card for them — their Claude Context pending card (see
        // ClaudeContextController::pendingDays) replaces it.
        if (! DailyReportsAccess::enabledFor($user)) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $userId = $user->id;
        $now = DateHelper::now();
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);

        $fieldKeys = KpiDefinition::where('user_id', $userId)
            ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
            ->pluck('field_key')
            ->toArray();

        if (empty($fieldKeys)) {
            return response()->json(['ok' => true, 'items' => []]);
        }

        $pending = [];
        $yesterdayIndex = min($now->dayOfWeekIso - 2, 4); // up to yesterday only, cap at Fri
        $holidays = config('holidays', []);

        // An approved leave (or company holiday) means no daily report was
        // expected, so that day must not be flagged as pending. WFH and
        // Permission are working days — the person is working — so they are
        // excluded here and the day still counts.
        $leaveRows = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereHas('leaveType', function ($q) {
                $q->whereNotIn('slug', ['wfh', 'permission']);
            })
            ->where('start_date', '<=', $weekStart->copy()->addDays(max($yesterdayIndex, 0))->toDateString())
            ->where('end_date', '>=', $weekStart->toDateString())
            ->get(['start_date', 'end_date']);

        // Compare on the date string alone — the cursor runs in Asia/Kolkata
        // while DB datetimes are UTC, and the 5:30 offset otherwise lets a
        // same-day leave slip through a timestamp comparison.
        $leaveBounds = $leaveRows->map(function ($lr) {
            $s = $lr->start_date instanceof \Carbon\CarbonInterface ? $lr->start_date->toDateString() : (string) $lr->start_date;
            $e = $lr->end_date instanceof \Carbon\CarbonInterface ? $lr->end_date->toDateString() : (string) $lr->end_date;
            return [substr($s, 0, 10), substr($e, 0, 10)];
        })->all();

        for ($i = 0; $i <= $yesterdayIndex; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dateStr = $date->format('Y-m-d');

            // Holiday — no report expected.
            if (array_key_exists($dateStr, $holidays)) {
                continue;
            }

            // Approved leave day — no report expected.
            $onLeave = false;
            foreach ($leaveBounds as [$s, $e]) {
                if ($dateStr >= $s && $dateStr <= $e) { $onLeave = true; break; }
            }
            if ($onLeave) {
                continue;
            }

            $filledCount = DailyReport::where('user_id', $userId)
                ->where('report_date', $dateStr)
                ->whereIn('field_key', $fieldKeys)
                ->where('value', '!=', '')
                ->count();

            if ($filledCount === 0) {
                $pending[] = [
                    'date' => $dateStr,
                    'dayLabel' => $date->format('l, d M'),
                    'isOverdue' => true,
                ];
            }
        }

        return response()->json(['ok' => true, 'items' => $pending]);
    }
}
