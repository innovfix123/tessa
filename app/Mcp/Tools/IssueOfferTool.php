<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class IssueOfferTool extends Tool
{
    public function name(): string { return 'issue_offer'; }
    public function description(): string
    {
        return 'Issue the offer letter for a candidate (HR only; the HR interview round must be passed first).';
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
        return ApiSubRequest::post("/hiring/candidates/{$args['candidate_id']}/issue-offer", [], $user);
    }
}
