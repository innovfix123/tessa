<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteLabelTool extends Tool
{
    public function name(): string { return 'delete_label'; }
    public function description(): string
    {
        return 'Delete an agile label.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['label_id' => ['type' => 'integer']],
            'required' => ['label_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/labels/{$args['label_id']}", [], $user);
    }
}
