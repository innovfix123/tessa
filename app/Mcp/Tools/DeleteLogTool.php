<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteLogTool extends Tool
{
    public function name(): string { return 'delete_log'; }
    public function description(): string
    {
        return 'Delete one of your own daily log entries by id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'log_id' => ['type' => 'integer'],
            ],
            'required' => ['log_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/logs/{$args['log_id']}", [], $user);
    }
}
