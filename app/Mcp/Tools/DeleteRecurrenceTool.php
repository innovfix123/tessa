<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteRecurrenceTool extends Tool
{
    public function name(): string { return 'delete_recurrence'; }
    public function description(): string
    {
        return 'Delete a recurring-task template by id (stops future auto-created tasks).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['recurrence_id' => ['type' => 'integer']],
            'required' => ['recurrence_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/tessa/recurrences/{$args['recurrence_id']}", [], $user);
    }
}
