<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Feature 5 — upsert an employee's master-record fields into the HR Google Sheet.
 * The sheet has NO email column, so the key is the employee NAME ("Name of the
 * Employee"). Header-matched EXACTLY (normalized) — only the columns Tessa actually
 * stores are written; every other column (Sr. No., Marital Status, Aadhar Number,
 * Father's Name, Pin code, Bank Name, Nominee…) is left untouched.
 *
 * Writes through a connected HR member's Google account (GoogleHrWriter →
 * GoogleUserService), NOT a service account. Returns false (no throw) when no HR
 * writer is connected or the sheet id is unset.
 */
class GoogleSheetsService
{
    /** Normalized header of the key (identity) column. */
    private const KEY_HEADER = 'name of the employee';

    public function __construct(private GoogleHrWriter $writer) {}

    public function isConfigured(): bool
    {
        return $this->writer->hasWriter()
            && ! empty(config('services.google.service_account.sheet_id'));
    }

    private function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower($s)));
    }

    /** Exact (normalized) sheet header => employee value, for the columns Tessa has. */
    private function valueFor(string $normHeader, User $u): ?string
    {
        return match ($normHeader) {
            'name of the employee' => $u->name,
            'date of birth' => optional($u->date_of_birth)->format('d-m-Y'),
            'mob. no.' => $u->personal_mobile,
            'date of joining' => optional($u->joining_date)->format('d-m-Y'),
            'residence address' => $u->permanent_address, // ESIC register uses the permanent/home address, not current
            'bank a/c no.' => $u->bank_account_number,
            'bank ifsc' => $u->bank_ifsc_code,
            default => null, // unmapped column -> leave the cell untouched
        };
    }

    /**
     * Upsert the employee's row, keyed by name. Returns true on success, false when
     * unconfigured or on a handled failure (logged). Never throws to the caller.
     */
    public function upsertEmployeeRow(User $user): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $client = $this->writer->client();
            if (! $client) {
                return false;
            }

            $sheetId = (string) config('services.google.service_account.sheet_id');
            $tab = (string) config('services.google.service_account.sheet_tab', 'Sheet1');

            $rows = $client->readSheetValues($sheetId, $tab);
            $headers = $rows[0] ?? [];
            if (empty($headers)) {
                Log::warning('GoogleSheetsService: sheet has no header row; aborting to avoid clobbering.');

                return false;
            }

            // Resolve the key column + the value for each mapped header.
            $keyCol = null;
            $colValue = []; // column index => value to write
            foreach ($headers as $i => $header) {
                $nh = $this->norm((string) $header);
                if ($keyCol === null && $nh === self::KEY_HEADER) {
                    $keyCol = $i;
                }
                $value = $this->valueFor($nh, $user);
                if ($value !== null && $value !== '') {
                    $colValue[$i] = $value;
                }
            }
            if ($keyCol === null) {
                Log::warning('GoogleSheetsService: no "Name of the Employee" header found; aborting upsert.');

                return false;
            }

            // Find the existing row by normalized name (data rows start at index 1).
            $targetName = $this->norm((string) $user->name);
            $targetRow = null; // 1-based sheet row number
            foreach (array_slice($rows, 1, null, true) as $idx => $row) {
                if (isset($row[$keyCol]) && $this->norm((string) $row[$keyCol]) === $targetName) {
                    $targetRow = $idx + 1;
                    break;
                }
            }

            if ($targetRow !== null) {
                // Overlay mapped cells onto the existing row, preserving the rest.
                $existing = $rows[$targetRow - 1] ?? [];
                $width = max(count($headers), count($existing));
                $out = [];
                for ($i = 0; $i < $width; $i++) {
                    $out[$i] = array_key_exists($i, $colValue) ? $colValue[$i] : ($existing[$i] ?? '');
                }
                $client->updateSheetRange($sheetId, $tab . '!A' . $targetRow, $out);

                return true;
            }

            // Append a new full-width row with mapped values placed.
            $out = [];
            for ($i = 0; $i < count($headers); $i++) {
                $out[$i] = array_key_exists($i, $colValue) ? $colValue[$i] : '';
            }
            $client->appendSheetRow($sheetId, $tab, $out);

            return true;
        } catch (\Throwable $e) {
            Log::warning('GoogleSheetsService: upsert failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
