<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MarkProvisioningTool extends Tool
{
    public function name(): string { return 'mark_provisioning'; }
    public function description(): string
    {
        return 'Mark a candidate provisioning step (Tessa account or workspace) done or not-done.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'task' => ['type' => 'string', 'enum' => ['tessa', 'workspace']],
                'done' => ['type' => 'boolean'],
            ],
            'required' => ['candidate_id', 'done'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['candidate_id'];
        unset($args['candidate_id']);
        return ApiSubRequest::post("/hiring/candidates/{$id}/provisioning/mark", $args, $user);
    }
}
