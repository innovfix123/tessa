<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class LogClaudeContextTool extends Tool
{
    public function name(): string { return 'log_claude_context'; }

    public function description(): string
    {
        return 'Record the signed-in user\'s end-of-day work summary ("Claude context") for today in Tessa. '
            .'Call this when the user asks you to summarize their day and send it to Tessa (typically at sign-off). '
            .'Write a concise summary of what they worked on in THIS chat. One entry per day: once recorded it is '
            .'final and cannot be edited (a second call the same day is rejected). The user should start a fresh chat each day.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Concise plain-text summary of what the user worked on today / what they used Claude for.',
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional short usage tags, e.g. ["coding","research","writing","debugging"].',
                ],
            ],
            'required' => ['summary'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = ['summary' => $args['summary']];
        if (isset($args['categories'])) {
            $body['categories'] = $args['categories'];
        }

        return ApiSubRequest::post('/claude-context', $body, $user);
    }
}
