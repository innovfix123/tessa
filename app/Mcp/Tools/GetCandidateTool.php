<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetCandidateTool extends Tool
{
    public function name(): string { return 'get_candidate'; }
    public function description(): string
    {
        return 'Get one candidate with their status, interviews, and provisioning/offer state.';
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
        return ApiSubRequest::get("/hiring/candidates/{$args['candidate_id']}", [], $user);
    }
}
