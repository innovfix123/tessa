<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddNetworkLeverageTool extends Tool
{
    public function name(): string { return 'add_network_leverage'; }
    public function description(): string
    {
        return 'Log a network-leverage event — an industry event you attended, who you met, and the LinkedIn connections made.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week_key' => ['type' => 'string', 'description' => 'The week, e.g. YYYY-MM-DD.'],
                'event_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'event_name' => ['type' => 'string'],
                'co_attendees' => ['type' => 'string'],
                'attendee_count' => ['type' => 'integer'],
                'contacts' => ['type' => 'string'],
                'linkedin_urls' => ['type' => 'string', 'description' => 'LinkedIn profile URLs of contacts made.'],
            ],
            'required' => ['week_key', 'event_date', 'event_name', 'linkedin_urls'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/network-leverage', $args, $user);
    }
}
