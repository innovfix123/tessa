<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListLabelsTool extends Tool
{
    public function name(): string { return 'list_labels'; }
    public function description(): string
    {
        return 'List agile labels (for tagging stories and bugs).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/labels', [], $user);
    }
}
