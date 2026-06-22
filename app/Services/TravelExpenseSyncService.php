<?php

namespace App\Services;

use App\Models\TravelExpense;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mirror logged travel trips into the WRITER's Google Drive (Shoyab #32, else Ayush
 * #4 — see TravelLedgerWriter), via that admin's "Connect Google" OAuth token (no
 * service account):
 *
 *   • each payment screenshot is uploaded into a folder tree
 *     `Travel Expenses / {Month} / {Employee} / {Date} / file`, renamed to
 *     `<Employee>_<From>_<To>_<DD-MM-YYYY>_<Amount>.<ext>` (e.g.
 *     "Travel Expenses/June 2026/Bhoomika/17-06-2026/Bhoomika_Home_Office_17-06-2026_250.jpg"); and
 *   • a master ledger Google Sheet (auto-created in the root folder) is REBUILT from
 *     the DB with ONE TAB PER EMPLOYEE (named after them): columns S.No / Date /
 *     Description / From / To / Amount / Screenshot Link, each tab closed by a bold
 *     Total row.
 *
 * Idempotent by construction: a screenshot already carrying a `drive_file_id` is
 * never re-uploaded, and the sheet is a pure projection of `travel_expenses` (tabs are
 * reconciled against the current employee set each run), so a rebuild can never
 * duplicate a row. Dormant-safe: every entry point returns
 * false/no-op (never throws to the caller) when no write-scoped account is
 * connected — so trips always save locally and `travel:sync` backfills later. A
 * cross-process Cache lock serialises rebuilds (DB cache driver) so two near-
 * simultaneous trips can't create duplicate folders/sheets or clobber each other.
 */
class TravelExpenseSyncService
{
    private const SHEET_MIME = 'application/vnd.google-apps.spreadsheet';

    private const LOCK = 'travel-ledger-sync';

    public function __construct(private TravelLedgerWriter $writer) {}

    public function isConfigured(): bool
    {
        return $this->writer->isConfigured();
    }

    /**
     * Sync one trip: upload its screenshot (once) and rebuild the ledger, then stamp
     * it filed. Returns true on success, false when unconfigured or on a handled
     * failure (logged). Safe to call from app()->terminating() — never throws.
     */
    public function syncTrip(TravelExpense $trip): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            return (bool) Cache::lock(self::LOCK, 60)->block(20, function () use ($trip) {
                $client = $this->writer->client();
                if (! $client) {
                    return false;
                }

                $this->ensureScreenshotUploaded($trip, $client);
                $this->rebuildLedger($client);
                $trip->update(['sheet_synced_at' => now()]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::warning('TravelExpenseSyncService: syncTrip failed', ['trip' => $trip->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Backfill: upload every still-unfiled screenshot, then rebuild the ledger ONCE,
     * then stamp the processed trips. Used by `travel:sync`. Returns a summary.
     *
     * @param array{user?:int,month?:string} $filters
     */
    public function syncAll(array $filters = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        try {
            return (array) Cache::lock(self::LOCK, 180)->block(30, function () use ($filters) {
                $client = $this->writer->client();
                if (! $client) {
                    return ['ok' => false, 'reason' => 'no_client'];
                }

                $query = TravelExpense::query();
                if (! empty($filters['user'])) {
                    $query->where('user_id', (int) $filters['user']);
                }
                if (! empty($filters['month'])) {
                    $query->where('month_key', $filters['month']);
                }
                $trips = $query->orderBy('id')->get();

                // Process all trips — ensureScreenshotUploaded is idempotent per item and
                // handles partial uploads (trips where some screenshots are synced but others aren't).
                $uploaded = 0;
                foreach ($trips as $trip) {
                    $hadFileId = ! empty($trip->drive_file_id);
                    $this->ensureScreenshotUploaded($trip, $client);
                    $trip->refresh();
                    if (! $hadFileId && ! empty($trip->drive_file_id)) {
                        $uploaded++;
                    }
                }

                $rebuilt = $this->rebuildLedger($client);
                if ($rebuilt) {
                    // Every trip is now represented in the sheet — mark the unfiled ones filed.
                    $ids = $trips->whereNull('sheet_synced_at')->pluck('id');
                    if ($ids->isNotEmpty()) {
                        TravelExpense::whereIn('id', $ids)->update(['sheet_synced_at' => now()]);
                    }
                }

                return ['ok' => true, 'trips' => $trips->count(), 'uploaded' => $uploaded, 'rebuilt' => $rebuilt];
            });
        } catch (\Throwable $e) {
            Log::warning('TravelExpenseSyncService: syncAll failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ── Drive: screenshot upload ──────────────────────────────────────────────

    /**
     * Upload every unsynced screenshot in the trip's `screenshots` JSON into
     * "Travel Expenses/{Month}/{Employee}/{Date}" (idempotent per item).
     * Falls back to the legacy scalar `screenshot_path` for rows predating the
     * multi-screenshot migration.
     */
    private function ensureScreenshotUploaded(TravelExpense $trip, GoogleUserService $client): void
    {
        $screenshots = $trip->screenshots;

        // Legacy fallback: synthesise a single-item array from the scalar columns.
        if (empty($screenshots) && $trip->screenshot_path) {
            $screenshots = [[
                'path'          => $trip->screenshot_path,
                'name'          => $trip->screenshot_name ?? '',
                'drive_file_id' => $trip->drive_file_id ?? null,
                'drive_link'    => $trip->drive_link ?? null,
            ]];
        }

        if (empty($screenshots)) {
            return;
        }

        $user     = $trip->submitter ?: User::find($trip->user_id);
        $employee = trim((string) (optional($user)->name ?? '')) ?: ('User ' . $trip->user_id);

        $folderId = null; // resolved lazily on first upload needed
        $changed  = false;
        $total    = count($screenshots);

        foreach ($screenshots as $i => &$item) {
            if (! empty($item['drive_file_id'])) {
                continue; // already filed — idempotent
            }
            $path = $item['path'] ?? null;
            if (! $path || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            if (! $folderId) {
                $folderId = $this->ensureFolderPath($client, [
                    $trip->trip_date->format('F Y'),   // June 2026
                    $employee,                         // Bhoomika
                    $trip->trip_date->format('d-m-Y'), // 17-06-2026
                ]);
                if (! $folderId) {
                    return;
                }
            }

            $abs  = Storage::disk('public')->path($path);
            $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION) ?: 'jpg');
            $name = $this->screenshotName($trip, $employee, $ext, $total > 1 ? $i + 1 : 0);
            $mime = mime_content_type($abs) ?: 'application/octet-stream';

            $fileId = $client->uploadFileToFolder($folderId, $name, (string) file_get_contents($abs), $mime);
            if ($fileId) {
                $client->makeFilePublicLink($fileId);
                $item['drive_file_id'] = $fileId;
                $item['drive_link']    = 'https://drive.google.com/file/d/' . $fileId . '/view';
                $changed = true;
            }
        }
        unset($item);

        if ($changed) {
            $update = ['screenshots' => $screenshots];
            // Keep scalar columns in sync with the first item so legacy queries still work.
            if (! empty($screenshots[0]['drive_file_id'])) {
                $update['drive_file_id'] = $screenshots[0]['drive_file_id'];
                $update['drive_link']    = $screenshots[0]['drive_link'];
            }
            $trip->update($update);
        }
    }

    /**
     * Drive filename for a trip's screenshot: <Employee>_<From>_<To>_<DD-MM-YYYY>_<Amount>[_N].<ext>
     * (e.g. "Bhoomika_Home_Office_17-06-2026_250_2.jpg"). $seq > 0 appends "_N" to differentiate
     * multiple screenshots for the same trip. Each text part is slugged.
     * The amount drops trailing zeros (250.00 → "250").
     */
    private function screenshotName(TravelExpense $trip, string $employee, string $ext, int $seq = 0): string
    {
        $slug = fn ($s) => preg_replace('/\s+/', '-', trim(preg_replace('#[\\\\/:*?"<>|_]+#', ' ', (string) $s))) ?: 'NA';
        $amount = rtrim(rtrim(number_format((float) $trip->amount, 2, '.', ''), '0'), '.');
        $seqSuffix = $seq > 0 ? ('_' . $seq) : '';

        return implode('_', [
            $slug($employee),
            $slug($trip->from_label),
            $slug($trip->to_label),
            $trip->trip_date->format('d-m-Y'),
            $amount !== '' ? $amount : '0',
        ]) . $seqSuffix . '.' . $ext;
    }

    /** Walk [Month, Employee, Date] under the "Travel Expenses" root, find-or-creating each. */
    private function ensureFolderPath(GoogleUserService $client, array $segments): ?string
    {
        $parent = $this->ensureRootFolder($client);
        if (! $parent) {
            return null;
        }
        foreach ($segments as $name) {
            $parent = $client->ensureChildFolder($parent, (string) $name);
            if (! $parent) {
                return null;
            }
        }

        return $parent;
    }

    /** The "Travel Expenses" root folder under the writer's My Drive (pin via config, else find-or-create). */
    private function ensureRootFolder(GoogleUserService $client): ?string
    {
        if ($pinned = (string) config('travel_expenses.drive_root_folder_id')) {
            return $pinned;
        }

        $id = $client->ensureChildFolder('root', (string) config('travel_expenses.root_folder_name', 'Travel Expenses'));
        if ($id) {
            $this->shareOnce($client, $id);
        }

        return $id;
    }

    // ── Sheets: master ledger rebuild ─────────────────────────────────────────

    /**
     * Rebuild the whole ledger Sheet from the DB: ONE TAB PER EMPLOYEE (named after them),
     * each a fresh projection of that employee's trips closed by a bold Total row. Tabs are
     * reconciled against the current employee set every run (add missing, then prune stale)
     * so the workbook can never drift or duplicate.
     */
    private function rebuildLedger(GoogleUserService $client): bool
    {
        $rootId = $this->ensureRootFolder($client);
        if (! $rootId) {
            return false;
        }
        $sheet = $this->ensureLedgerSheet($client, $rootId);
        if (! $sheet) {
            return false;
        }
        $sheetId = (string) $sheet['id'];

        // Group by employee, sort trips within a group by date, sort groups by name.
        $groups = TravelExpense::with('submitter')->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->sortBy([['trip_date', 'asc'], ['id', 'asc']])->values())
            ->sortBy(fn ($g) => mb_strtolower((string) (optional($g->first()->submitter)->name ?? 'zzz')))
            ->values();

        // Each employee → a unique Sheets-safe tab title + its full value matrix.
        $used = [];
        $tabs = [];
        foreach ($groups as $g) {
            $first = $g->first();
            $name = (string) (optional($first->submitter)->name ?: ('User ' . $first->user_id));
            $tabs[] = [
                'title' => $this->uniqueTabTitle($name, (int) $first->user_id, $used),
                'matrix' => $this->buildEmployeeMatrix($g),
            ];
        }

        // No trips at all: leave a single header-only placeholder; never delete the last sheet.
        if (empty($tabs)) {
            $headers = array_values((array) config('travel_expenses.ledger_headers'));
            $client->clearSheetValues($sheetId, $this->a1($sheet['tab'], 'A:Z'));
            $client->updateSheetValues($sheetId, $this->a1($sheet['tab'], 'A1'), [$headers], 'USER_ENTERED');
            $client->batchUpdateSpreadsheet($sheetId, $this->sheetFormatting((int) $sheet['gid'], 1));

            return true;
        }

        // 1. Add any tab that doesn't exist yet (BEFORE deletes, so ≥1 sheet always remains).
        $existing = array_column($client->getSpreadsheetSheets($sheetId), 'title');
        $missing = array_values(array_diff(array_column($tabs, 'title'), $existing));
        if ($missing) {
            $client->batchUpdateSpreadsheet($sheetId, array_map(
                fn ($t) => ['addSheet' => ['properties' => ['title' => $t]]],
                $missing
            ));
        }

        // 2. Map title → gid (after the adds), then write + format each employee tab.
        $gidByTitle = [];
        foreach ($client->getSpreadsheetSheets($sheetId) as $s) {
            $gidByTitle[$s['title']] = $s['gid'];
        }
        foreach ($tabs as $tab) {
            $gid = $gidByTitle[$tab['title']] ?? null;
            if ($gid === null) {
                continue;
            }
            $client->clearSheetValues($sheetId, $this->a1($tab['title'], 'A:Z'));
            $client->updateSheetValues($sheetId, $this->a1($tab['title'], 'A1'), $tab['matrix'], 'USER_ENTERED');
            $client->batchUpdateSpreadsheet($sheetId, array_merge(
                $this->sheetFormatting((int) $gid, count($tab['matrix'])),
                $this->linkCellRequests((int) $gid, $tab['matrix'])
            ));
        }

        // 3. Prune tabs that no longer map to an employee (stale names, the default "Sheet1", …).
        $keep = array_column($tabs, 'title');
        $deletes = [];
        foreach ($client->getSpreadsheetSheets($sheetId) as $s) {
            if (! in_array($s['title'], $keep, true)) {
                $deletes[] = ['deleteSheet' => ['sheetId' => $s['gid']]];
            }
        }
        if ($deletes) {
            $client->batchUpdateSpreadsheet($sheetId, $deletes);
        }

        return true;
    }

    /** Find-or-create the master ledger spreadsheet; return ['id','tab','gid']. */
    private function ensureLedgerSheet(GoogleUserService $client, string $rootFolderId): ?array
    {
        $tabCfg = (string) config('travel_expenses.ledger_sheet_tab', 'Sheet1');

        if ($pinnedId = (string) config('travel_expenses.ledger_sheet_id')) {
            $first = $client->getSpreadsheetSheets($pinnedId)[0] ?? null;

            return ['id' => $pinnedId, 'tab' => $first['title'] ?? $tabCfg, 'gid' => $first['gid'] ?? 0];
        }

        $name = (string) config('travel_expenses.ledger_sheet_name', 'Travel Expenses — Master Ledger');

        if ($found = $client->findFile($rootFolderId, $name, self::SHEET_MIME)) {
            $first = $client->getSpreadsheetSheets($found['id'])[0] ?? ['gid' => 0, 'title' => $tabCfg];

            return ['id' => $found['id'], 'tab' => $first['title'], 'gid' => $first['gid']];
        }

        $created = $client->createSpreadsheetInFolder($rootFolderId, $name);
        if (! $created) {
            return null;
        }
        $this->shareOnce($client, $created['id']);

        return ['id' => $created['id'], 'tab' => $created['tab'], 'gid' => $created['gid']];
    }

    /**
     * One employee's value matrix: header row + a row per trip (S.No / Date / Description /
     * From / To / Amount / Screenshot Link) + a bold Total row. The link is the raw Drive
     * screenshot URL (clickable under USER_ENTERED), blank until that trip's screenshot is uploaded.
     */
    private function buildEmployeeMatrix($trips): array
    {
        $headers = array_values((array) config('travel_expenses.ledger_headers'));
        $matrix = [$headers];

        $sno = 1;
        foreach ($trips as $t) {
            $link = (string) ($t->drive_link ?? '');
            $matrix[] = [
                $sno++,
                optional($t->trip_date)->format('d-m-Y'),
                (string) ($t->note ?? ''),
                $t->from_label,
                $t->to_label,
                (float) $t->amount,
                $link,
            ];
        }

        // Bold Total row (highlighted by sheetFormatting): label under "To", sum under "Amount".
        $matrix[] = ['', '', '', '', 'Total', round((float) $trips->sum('amount'), 2), ''];

        return $matrix;
    }

    /**
     * A Google-Sheets-safe, unique tab title for an employee. Strips characters Sheets forbids
     * in tab names ([ ] : * ? / \), collapses whitespace, caps length, falls back to "User {id}",
     * and disambiguates a repeated display name by appending " (#id)".
     */
    private function uniqueTabTitle(string $name, int $userId, array &$used): string
    {
        $title = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('#[\[\]:*?/\\\\]+#', ' ', $name)));
        if ($title === '') {
            $title = 'User ' . $userId;
        }
        if (mb_strlen($title) > 95) {
            $title = rtrim(mb_substr($title, 0, 95));
        }
        if (isset($used[mb_strtolower($title)])) {
            $title = mb_substr($title, 0, 88) . ' (#' . $userId . ')';
        }
        $used[mb_strtolower($title)] = true;

        return $title;
    }

    /**
     * Per-tab repeatCell/freeze requests: reset a generous band, bold + light-grey the header
     * row, freeze it, and bold + amber the final Total row (the last row in the matrix).
     */
    private function sheetFormatting(int $gid, int $rowCount): array
    {
        $cols = count((array) config('travel_expenses.ledger_headers'));

        $format = fn (array $range, array $fmt) => [
            'repeatCell' => [
                'range' => array_merge(['sheetId' => $gid], $range),
                'cell' => ['userEnteredFormat' => $fmt],
                'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
            ],
        ];

        $requests = [
            // 1. Reset formatting on a generous band so stale highlights vanish when rows shrink.
            $format(
                ['startRowIndex' => 0, 'endRowIndex' => max($rowCount, 1) + 200, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                ['textFormat' => ['bold' => false], 'backgroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1]]
            ),
            // 2. Header row: bold on a light-grey fill.
            $format(
                ['startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 0.85, 'green' => 0.85, 'blue' => 0.85]]
            ),
            // 3. Freeze the header row.
            [
                'updateSheetProperties' => [
                    'properties' => ['sheetId' => $gid, 'gridProperties' => ['frozenRowCount' => 1]],
                    'fields' => 'gridProperties.frozenRowCount',
                ],
            ],
        ];

        // 4. Total row (last row): bold on a soft amber highlight.
        if ($rowCount >= 2) {
            $requests[] = $format(
                ['startRowIndex' => $rowCount - 1, 'endRowIndex' => $rowCount, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                ['textFormat' => ['bold' => true], 'backgroundColor' => ['red' => 1, 'green' => 0.95, 'blue' => 0.7]]
            );
        }

        return $requests;
    }

    /**
     * One updateCells request per row whose "Screenshot Link" cell (col G / index 6) holds a URL,
     * turning that plain string into a TRUE clickable hyperlink via a textFormatRuns link. Unlike a
     * bare URL (not clickable) or the read-only CellData.hyperlink (formula-only), TextFormat.link is
     * writable and renders clickable with no =HYPERLINK formula. Skips header row 0 and the Total row.
     */
    private function linkCellRequests(int $gid, array $matrix): array
    {
        $requests = [];
        $last = count($matrix) - 1; // trailing Total row
        for ($r = 1; $r < $last; $r++) {
            $url = (string) ($matrix[$r][6] ?? '');
            if (! str_starts_with($url, 'http')) {
                continue; // blank cell (no screenshot yet) — leave empty
            }
            $requests[] = [
                'updateCells' => [
                    'range' => [
                        'sheetId'          => $gid,
                        'startRowIndex'    => $r,
                        'endRowIndex'      => $r + 1,
                        'startColumnIndex' => 6,
                        'endColumnIndex'   => 7,
                    ],
                    'rows'   => [['values' => [[
                        'userEnteredValue' => ['stringValue' => $url],
                        'textFormatRuns'   => [
                            ['startIndex' => 0, 'format' => ['link' => ['uri' => $url]]],
                        ],
                    ]]]],
                    'fields' => 'userEnteredValue,textFormatRuns',
                ],
            ];
        }

        return $requests;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Share a folder/sheet (reader) with the configured emails, at most once/day per id. */
    private function shareOnce(GoogleUserService $client, string $fileId): void
    {
        foreach ((array) config('travel_expenses.share_emails', []) as $email) {
            $email = trim((string) $email);
            if ($email === '' || ! Cache::add('travel-share:' . $fileId . ':' . $email, true, now()->addDay())) {
                continue;
            }
            try {
                $client->shareFile($fileId, $email, 'reader');
            } catch (\Throwable $e) {
                Log::warning('TravelExpenseSyncService: share failed', ['email' => $email, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Quote a tab name into A1 notation (handles spaces/apostrophes): "'My Tab'!A1". */
    private function a1(string $tab, string $cells): string
    {
        return "'" . str_replace("'", "''", $tab) . "'!" . $cells;
    }
}
