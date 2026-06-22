<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Daily auto-fill of Anirudh's "CPA Master Sheet" (config/cpa_master_sheet.php):
 * fetch the Hima admin "user report" for a date and write ONLY the mapped metric
 * columns into that date's row. Ad-spend + formula columns are never touched —
 * each cell is written individually (per-cell updateSheetRange), never a whole-row
 * overwrite, so the in-sheet CPA/ROAS formulas survive.
 *
 * DORMANT-safe: returns an error string (never throws) until BOTH the Hima
 * endpoint is configured AND a write-capable Google account is connected. Writes
 * via OAuth through CpaSheetWriter -> GoogleUserService (the same path HR/Travel
 * syncs use); no Google service-account key is required.
 */
class HimaCpaSheetSyncService
{
    public function __construct(
        private CpaSheetWriter $writer,
        private HimaAnalyticsService $hima,
    ) {}

    public function isConfigured(): bool
    {
        return $this->writer->hasWriter()
            && (string) config('cpa_master_sheet.sheet_id', '') !== ''
            && (string) config('cpa_master_sheet.sheet_tab', '') !== ''
            && trim((string) config('cpa_master_sheet.sync.endpoint', '')) !== '';
    }

    /**
     * Fetch the Hima user report for $date, map its fields to sheet columns, and
     * (unless $dryRun) write each mapped cell into the matching date row.
     *
     * @return array{date:string, values:array<string,mixed>, target_cells:array<string,string>, written:int, error:?string}
     */
    public function syncForDate(string $date, bool $dryRun = false): array
    {
        $out = ['date' => $date, 'values' => [], 'target_cells' => [], 'written' => 0, 'error' => null];

        $fieldMap = (array) config('cpa_master_sheet.sync.field_map', []);
        if (! $fieldMap) {
            $out['error'] = 'no field_map configured';
            return $out;
        }

        $report = $this->hima->getUserReport($date);
        if ($report === null) {
            $out['error'] = 'no Hima user-report data (endpoint unset or fetch failed)';
            return $out;
        }

        // The endpoint exposes total + female registrations but no male figure, while
        // Anirudh's "Registration" column wants Total Male Registered. Derive it
        // (total − female) when the endpoint hasn't supplied it directly — prefers a
        // real field if the endpoint ever adds one.
        if (Arr::get($report, 'data.total_male_registered') === null) {
            $total = Arr::get($report, 'data.total_registration');
            $female = Arr::get($report, 'data.total_female_registered');
            if (is_numeric($total) && is_numeric($female)) {
                data_set($report, 'data.total_male_registered', (int) $total - (int) $female);
            }
        }

        // Resolve each sheet column header => value from the report (dot-notation).
        foreach ($fieldMap as $header => $path) {
            $out['values'][$header] = Arr::get($report, $path);
        }

        if ($dryRun) {
            return $out;
        }

        if (! $this->writer->hasWriter()) {
            $out['error'] = 'no write-capable Google account connected (Anirudh must reconnect Google)';
            return $out;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                $out['error'] = 'no write-capable Google account connected (Anirudh must reconnect Google)';
                return $out;
            }

            $sheetId = (string) config('cpa_master_sheet.sheet_id', '');
            $tab = (string) config('cpa_master_sheet.sheet_tab', '');
            if ($sheetId === '' || $tab === '') {
                $out['error'] = 'sheet_id or sheet_tab not configured';
                return $out;
            }

            $rows = $client->readSheetValues($sheetId, $tab);
            if (! $rows) {
                $out['error'] = 'sheet read returned no rows';
                return $out;
            }

            // Locate the header row. This sheet's headers are NOT on row 1 (rows
            // above hold a blank spacer + a totals row), so find the row whose
            // column A is "Date", else the first row containing a mapped header.
            $headers = $this->findHeaderRow($rows, array_keys($fieldMap));
            if (! $headers) {
                $out['error'] = 'could not locate the header row in the sheet';
                return $out;
            }

            // header (lowercased/trimmed) => 0-based column index
            $colIndex = [];
            foreach ($headers as $i => $h) {
                $colIndex[mb_strtolower(trim((string) $h))] = $i;
            }

            $targetRow = $this->findDateRow($rows, $date);
            if ($targetRow === null) {
                $out['error'] = "no row found for date {$date} in column A";
                return $out;
            }

            // Write each mapped cell individually (skip null values + headers not
            // in the sheet). Per-cell writes never touch the formula columns.
            $written = 0;
            foreach ($fieldMap as $header => $path) {
                $value = $out['values'][$header] ?? null;
                if ($value === null) {
                    continue;
                }
                $key = mb_strtolower(trim((string) $header));
                if (! array_key_exists($key, $colIndex)) {
                    Log::warning('HimaCpaSheetSync: header not found in sheet', ['header' => $header]);
                    continue;
                }
                $cell = $this->colLetter($colIndex[$key]) . $targetRow;
                $client->updateSheetRange($sheetId, $tab . '!' . $cell, [$value]);
                $out['target_cells'][$header] = $cell;
                $written++;
            }

            if ($written === 0) {
                $out['error'] = 'nothing to write (all mapped values null or headers missing)';
                return $out;
            }

            $out['written'] = $written;
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            Log::error('HimaCpaSheetSync: exception', ['date' => $date, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Find the sheet's header row: prefer the row whose column A is "Date", else
     * the first row containing any of $wantedHeaders; null if neither is found.
     *
     * @param  array<int,array<int,mixed>>  $rows
     * @param  array<int,string>  $wantedHeaders
     * @return array<int,mixed>|null
     */
    private function findHeaderRow(array $rows, array $wantedHeaders): ?array
    {
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) ($row[0] ?? ''))) === 'date') {
                return $row;
            }
        }

        $wanted = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $wantedHeaders);
        foreach ($rows as $row) {
            foreach ((array) $row as $cell) {
                if (in_array(mb_strtolower(trim((string) $cell)), $wanted, true)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /** 1-based sheet row number whose column A parses to $date (Y-m-d), or null. */
    private function findDateRow(array $rows, string $date): ?int
    {
        $target = $this->parseSheetDate($date);
        if ($target === null) {
            return null;
        }

        foreach ($rows as $idx => $row) {
            $parsed = $this->parseSheetDate(trim((string) ($row[0] ?? '')));
            if ($parsed !== null && $parsed === $target) {
                return $idx + 1; // $idx is 0-based incl. header → +1 = 1-based row number
            }
        }

        return null;
    }

    /** Parse a sheet date cell (e.g. "1-Jun-26") or YYYY-MM-DD to Y-m-d, or null. */
    private function parseSheetDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach (['j-M-y', 'd-M-y', 'j-M-Y', 'd-M-Y', 'Y-m-d'] as $fmt) {
            $dt = Carbon::createFromFormat($fmt, $value);
            if ($dt !== false && $dt->format($fmt) === $value) {
                return $dt->toDateString();
            }
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 0-based column index → A1 letters (0=A, 25=Z, 26=AA, …). */
    private function colLetter(int $index): string
    {
        $letters = '';
        $n = $index;
        while (true) {
            $letters = chr(65 + ($n % 26)) . $letters;
            $n = intdiv($n, 26) - 1;
            if ($n < 0) {
                break;
            }
        }

        return $letters;
    }
}
