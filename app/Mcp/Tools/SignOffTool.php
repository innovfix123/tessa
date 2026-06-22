<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\User;
use Illuminate\Http\Request;

class SignOffTool extends Tool
{
    public function name(): string { return 'sign_off'; }
    public function description(): string
    {
        return 'Record your end-of-day sign-off (IST). Requires your Daily Report to be filled, plus any owned meeting agendas/notes and the Friday work-quality review — same rules as the portal, with no override. If items remain it returns 422 listing them; fill your Daily Report with update_daily_report_field, then retry. Idempotent once signed off.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        try {
            return ApiSubRequest::post('/signoff', [], $user);
        } catch (ToolException $e) {
            // The 422 gate response carries the per-section items (Daily Report,
            // agenda, notes, Friday review). Only the exception *message* reaches the
            // model — McpController drops $e->data for tools/call — so fold the specific
            // blockers, and exactly which Daily Report fields are still missing, into the
            // message the caller sees. Same gate as the portal; this just makes it legible.
            $items = is_array($e->data['items'] ?? null) ? $e->data['items'] : [];
            $blockers = array_values(array_filter($items, fn ($i) => ! empty($i['blocks'])));
            if ($e->statusCode !== 422 || $blockers === []) {
                throw $e;
            }

            $lines = ["Can't sign off yet — finish these first:"];
            foreach ($blockers as $b) {
                $line = '• '.($b['label'] ?? 'Item').' — '.($b['detail'] ?? 'incomplete');
                if (($b['type'] ?? '') === 'daily_report' && ! empty($b['missing'])) {
                    $names = array_map(fn ($m) => "{$m['label']} ({$m['key']})", $b['missing']);
                    $line .= '. Missing: '.implode(', ', $names)
                           .'. Fill each with update_daily_report_field (field_key + value).';
                }
                $lines[] = $line;
            }
            $lines[] = 'Then call sign_off again.';

            throw new ToolException(implode("\n", $lines), 422, $e->data, $e);
        }
    }
}
