<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListDashboardNotesTool extends Tool
{
    public function name(): string { return 'list_dashboard_notes'; }
    public function description(): string
    {
        return 'List the signed-in user\'s dashboard sticky notes (text + checklist notes pinned to the Tessa home).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/notes', [], $user);
    }
}
