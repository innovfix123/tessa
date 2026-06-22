<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListGoogleAdReportsTool extends Tool
{
    public function name(): string { return 'list_google_ad_reports'; }
    public function description(): string
    {
        return 'List Google ad-spend report rows.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/google-ad-reports', [], $user);
    }
}
