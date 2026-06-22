<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListLogsTool extends Tool
{
    public function name(): string { return 'list_logs'; }
    public function description(): string
    {
        return 'List your daily log feed — your manual log entries merged with activity, due tasks, and upcoming meetings.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/logs', [], $user);
    }
}
