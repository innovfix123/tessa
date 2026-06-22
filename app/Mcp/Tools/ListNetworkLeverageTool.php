<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListNetworkLeverageTool extends Tool
{
    public function name(): string { return 'list_network_leverage'; }
    public function description(): string
    {
        return 'List logged network-leverage events (industry events attended, contacts made, LinkedIn connections).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/network-leverage', [], $user);
    }
}
