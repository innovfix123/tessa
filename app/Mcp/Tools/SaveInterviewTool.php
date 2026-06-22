<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SaveInterviewTool extends Tool
{
    public function name(): string { return 'save_interview'; }
    public function description(): string
    {
        return 'Schedule or update a candidate interview round (technical or hr) — time, meet link, agenda, feedback, recording.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'round' => ['type' => 'string', 'enum' => ['technical', 'hr']],
                'scheduled_at' => ['type' => 'string', 'description' => 'ISO datetime or YYYY-MM-DD HH:MM.'],
                'meet_link' => ['type' => 'string'],
                'agenda' => ['type' => 'string'],
                'feedback' => ['type' => 'string'],
                'recording_link' => ['type' => 'string'],
                'email_subject' => ['type' => 'string'],
                'email_body' => ['type' => 'string'],
                'email_status' => ['type' => 'string', 'enum' => ['draft', 'sent']],
                'silent' => ['type' => 'boolean', 'description' => 'true skips sending the invite email.'],
            ],
            'required' => ['candidate_id', 'round'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['candidate_id'];
        unset($args['candidate_id']);
        return ApiSubRequest::post("/hiring/candidates/{$id}/interviews", $args, $user);
    }
}
