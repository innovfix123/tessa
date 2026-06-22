<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateHimaRevenueTool extends Tool
{
    public function name(): string { return 'update_hima_revenue'; }
    public function description(): string
    {
        return 'Update one day in the Hima daily-revenue sheet. Only editors can write (the API enforces it).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'collection' => ['type' => 'number'],
                'zocket_meta_ads_without_gst' => ['type' => 'number'],
                'hima_creator' => ['type' => 'number'],
                'g_ads_1_without_gst' => ['type' => 'number'],
                'g_ads_2_without_gst' => ['type' => 'number'],
                'payout' => ['type' => 'number'],
                'day0_revenue' => ['type' => 'number'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $date = $args['date'];
        unset($args['date']);
        return ApiSubRequest::put("/hima-revenue-sheet/{$date}", $args, $user);
    }
}
