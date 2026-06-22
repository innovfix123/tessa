<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddLogTool extends Tool
{
    public function name(): string { return 'add_log'; }
    public function description(): string
    {
        return 'Add an entry to your daily log. Tessa auto-categorizes it. A non-meaningful entry may be skipped (the response says so).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string', 'description' => 'What you did / are logging (max 10000 chars).'],
                'source' => ['type' => 'string', 'enum' => ['text', 'voice']],
            ],
            'required' => ['content'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = ['content' => $args['content']];
        if (isset($args['source'])) {
            $body['source'] = $args['source'];
        }
        return ApiSubRequest::post('/logs', $body, $user);
    }
}
