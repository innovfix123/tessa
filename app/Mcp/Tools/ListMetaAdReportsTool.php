<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMetaAdReportsTool extends Tool
{
    public function name(): string { return 'list_meta_ad_reports'; }
    public function description(): string
    {
        return 'List Meta (Facebook/Instagram) ad-spend report rows.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/meta-ad-reports', [], $user);
    }
}
