<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteNetworkLeverageTool extends Tool
{
    public function name(): string { return 'delete_network_leverage'; }
    public function description(): string
    {
        return 'Delete a logged network-leverage event by id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/network-leverage/{$args['id']}", [], $user);
    }
}
