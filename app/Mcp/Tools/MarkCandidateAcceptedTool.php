<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MarkCandidateAcceptedTool extends Tool
{
    public function name(): string { return 'mark_candidate_accepted'; }
    public function description(): string
    {
        return 'Mark a candidate\'s issued offer as accepted (HR only).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['candidate_id' => ['type' => 'integer']],
            'required' => ['candidate_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/hiring/candidates/{$args['candidate_id']}/mark-accepted", [], $user);
    }
}
