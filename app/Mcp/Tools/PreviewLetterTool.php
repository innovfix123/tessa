<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class PreviewLetterTool extends Tool
{
    public function name(): string { return 'preview_letter'; }
    public function description(): string
    {
        return 'Render a preview of an offer / appointment letter from the same templates used in the HR portal. Useful for dry-running before issuing.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'letter_type' => ['type' => 'string', 'enum' => ['offer', 'appointment']],
                'employee_category' => ['type' => 'string', 'enum' => ['freelancer', 'intern', 'fulltime']],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Template field values, e.g. recipient_name, recipient_email, designation, department, annual_ctc, joining_date. Keys vary by template; unknown keys are ignored and missing ones render blank.',
                    'additionalProperties' => true,
                ],
                'body_override_html' => ['type' => 'string', 'description' => 'Optional custom HTML body that replaces the rendered template body.'],
            ],
            'required' => ['letter_type', 'employee_category', 'fields'],
            'additionalProperties' => false,
        ];
    }
    // Gated by the 'letters' feature (FEATURE_MAP). LetterController re-checks.
    public function handle(array $args, User $user, Request $request): mixed
    {
        $res = ApiSubRequest::post('/letters/preview', $args, $user);
        // The full `html` embeds base64 letterhead/signature images (~500KB) that
        // blow past Claude's tool-result size limit and add nothing to a text
        // dry-run. Strip the binary blobs so the preview stays lightweight; the
        // styled PDF is still produced when the letter is actually issued.
        if (isset($res['html']) && is_string($res['html'])) {
            $res['html'] = preg_replace(
                '#(data:image/[^;]+;base64,)[A-Za-z0-9+/=\s]+#',
                '$1[image-stripped]',
                $res['html'],
            );
        }
        return $res;
    }
}
