<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteLetterTool extends Tool
{
    public function name(): string { return 'delete_letter'; }
    public function description(): string
    {
        return 'Delete an issued/draft letter (and its PDF) by id. Get the id from list_letters.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['letter_id' => ['type' => 'integer']],
            'required' => ['letter_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/letters/{$args['letter_id']}", [], $user);
    }
}
