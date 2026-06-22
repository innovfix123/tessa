<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateDailyReportFieldTool extends Tool
{
    public function name(): string { return 'update_daily_report_field'; }
    public function description(): string
    {
        return 'Set / add a value on today\'s (or a recent day\'s) daily report. Discover valid field_keys + their type via list_daily_reports first. Defaults user_id to the signed-in user. Note: free-text "work log" fields (input_type=textarea, e.g. "what did you work on today", "primary tasks", ad scripts) are multi-entry — each call ADDS an entry rather than overwriting. Plain number/status/text fields overwrite.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (today or yesterday — older dates are locked).'],
                'field_key' => ['type' => 'string'],
                'value' => ['type' => ['string', 'number', 'boolean', 'null'], 'description' => 'For textarea work-log fields this is the entry text; for status fields use "Done"/"Not Done"; for numeric KPIs a number.'],
                'user_id' => ['type' => 'integer'],
            ],
            'required' => ['report_date', 'field_key'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $targetUserId = $args['user_id'] ?? $user->id;
        $fieldKey = $args['field_key'];
        $reportDate = $args['report_date'];

        // The portal stores different field types in different places. A `textarea`
        // work-log field is a MULTI-ENTRY widget: its text lives in creative_uploads
        // and daily_reports.value only holds a COUNT. Writing prose straight into
        // daily_reports (the old behavior) made the value invisible in the portal
        // (the UI does parseInt(value) → 0 → "Add"). So route by input_type.
        $inputType = KpiDefinition::where('user_id', $targetUserId)
            ->where('field_key', $fieldKey)
            ->value('input_type') ?? 'text';

        // Normalize the value to a string (the API expects strings; a raw
        // number/bool would 500 inside preg_replace on the numeric path).
        $value = $args['value'] ?? null;
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }

        if ($inputType === 'upload') {
            throw new ToolException(
                "Field '{$fieldKey}' is a file-upload field — it needs an actual file, which can't be sent as text. Upload it in the Tessa portal.",
                422,
            );
        }

        if ($inputType === 'textarea') {
            return $this->saveTextEntry($targetUserId, $fieldKey, $reportDate, $value, $user);
        }

        return $this->saveScalar($targetUserId, $fieldKey, $reportDate, $value, $user);
    }

    /**
     * textarea work-log field → add a text entry via the creative-uploads API
     * (which also re-syncs the daily_reports count), then read the entries back.
     */
    private function saveTextEntry(int $userId, string $fieldKey, string $reportDate, string $value, User $user): array
    {
        if (trim($value) === '') {
            throw new ToolException("Field '{$fieldKey}' is a work-log field — provide the entry text in 'value'.", 422);
        }

        ApiSubRequest::post('/creative-uploads', [
            'action' => 'save_text',
            'user_id' => $userId,
            'report_date' => $reportDate,
            'field_key' => $fieldKey,
            'content' => $value,
        ], $user);

        $entries = [];
        try {
            $list = ApiSubRequest::get('/creative-uploads', [
                'user_id' => $userId,
                'report_date' => $reportDate,
                'field_key' => $fieldKey,
            ], $user);
            foreach ($list['uploads'] ?? [] as $u) {
                $entries[] = $u['content'] ?? $u['file_name'] ?? null;
            }
        } catch (\Throwable $e) {
            // Non-fatal — the entry already saved.
        }

        return [
            'ok' => true,
            'saved' => [
                'user_id' => $userId,
                'report_date' => $reportDate,
                'field_key' => $fieldKey,
                'added_entry' => $value,
            ],
            'all_entries_for_field' => array_values(array_filter($entries)),
            'note' => 'Added as a work-log entry on the Tessa daily report. This is a multi-entry field, so the text shows under the cell. Refresh the portal if it was already open.',
        ];
    }

    /**
     * number / status / text / text_multiline → overwrite daily_reports.value
     * directly, then read the day back as confirmation.
     */
    private function saveScalar(int $userId, string $fieldKey, string $reportDate, string $value, User $user): array
    {
        $save = ApiSubRequest::post('/daily-reports', [
            'action' => 'save_entry',
            'userId' => $userId,
            'reportDate' => $reportDate,
            'fieldKey' => $fieldKey,
            'value' => $value,
        ], $user);

        $stored = null;
        try {
            $readback = ApiSubRequest::get('/daily-reports', [
                'user_id' => $userId,
                'report_date' => $reportDate,
            ], $user);
            $stored = $readback['entries'] ?? null;
        } catch (\Throwable $e) {
            // Non-fatal — the save already succeeded.
        }

        return [
            'ok' => $save['ok'] ?? true,
            'saved' => [
                'user_id' => $userId,
                'report_date' => $reportDate,
                'field_key' => $fieldKey,
                'stored_value' => is_array($stored) ? ($stored[$fieldKey] ?? $value) : $value,
            ],
            'all_entries_for_day' => $stored,
            'note' => 'Saved to Tessa. The value above is what is now stored. If the Tessa portal was already open, refresh it to see the update.',
        ];
    }
}
