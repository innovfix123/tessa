<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SetInterviewOutcomeTool extends Tool
{
    public function name(): string { return 'set_interview_outcome'; }
    public function description(): string
    {
        return 'Record the outcome of a candidate interview round (passed or failed) with optional feedback.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'round' => ['type' => 'string', 'enum' => ['technical', 'hr']],
                'outcome' => ['type' => 'string', 'enum' => ['passed', 'failed']],
                'feedback' => ['type' => 'string'],
            ],
            'required' => ['candidate_id', 'round', 'outcome'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['candidate_id'];
        unset($args['candidate_id']);
        return ApiSubRequest::post("/hiring/candidates/{$id}/interviews/outcome", $args, $user);
    }
}
