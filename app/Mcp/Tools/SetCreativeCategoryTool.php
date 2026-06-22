<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SetCreativeCategoryTool extends Tool
{
    public function name(): string { return 'set_creative_category'; }
    public function description(): string
    {
        return "Set the team work-focus note shown to your direct reports on their dashboard and sign-in modal. Choose scope 'day' (today only, default) or 'week' (the whole week). Only the configured setters can call this.";
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'description' => "The work-focus note text."],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['day', 'week'],
                    'description' => "Whether the focus is for today only ('day', default) or the whole week ('week').",
                ],
            ],
            'required' => ['category'],
            'additionalProperties' => false,
        ];
    }

    // Setter-only (config('creative_category.setter_user_ids')). Override isAvailableTo —
    // allowedUserIds only GRANTS and never denies, so a sole user-id gate must live here.
    public function isAvailableTo(User $user): bool
    {
        $setters = array_map('intval', (array) config('creative_category.setter_user_ids', []));
        return in_array((int) $user->id, $setters, true);
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $payload = ['category' => $args['category']];
        if (isset($args['scope'])) {
            $payload['scope'] = $args['scope'];
        }
        return ApiSubRequest::post('/creative-category', $payload, $user);
    }
}
