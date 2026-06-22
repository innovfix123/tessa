<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListRecurrencesTool extends Tool
{
    public function name(): string { return 'list_recurrences'; }
    public function description(): string
    {
        return 'List recurring task templates (daily/weekly/monthly) and their schedules.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tessa/recurrences', [], $user);
    }
}
