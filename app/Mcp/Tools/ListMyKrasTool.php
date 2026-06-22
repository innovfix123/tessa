<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyKrasTool extends Tool
{
    public function name(): string { return 'list_my_kras'; }
    public function description(): string
    {
        return 'Fetch the signed-in user\'s KRAs / scorecard (current performance metrics + targets).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // KraScorecardController::show requires an explicit user_id; the portal
        // always passes the signed-in user's id, so mirror that here.
        return ApiSubRequest::get('/my-kras', ['user_id' => $user->id], $user);
    }
}
