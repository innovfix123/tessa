<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetCreativeCategoryTool extends Tool
{
    public function name(): string { return 'get_creative_category'; }
    public function description(): string
    {
        return "Get today's team work-focus note — the one you set (if you are a setter) and/or the one your reporting manager set for you.";
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    // Visible to a setter or to a direct report of a setter (mirrors CreativeCategoryController::show).
    public function isAvailableTo(User $user): bool
    {
        $setters = array_map('intval', (array) config('creative_category.setter_user_ids', []));
        return in_array((int) $user->id, $setters, true)
            || in_array((int) ($user->reporting_manager_id ?? 0), $setters, true);
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/creative-category', [], $user);
    }
}
