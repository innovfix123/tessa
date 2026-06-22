<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class ListLettersTool extends Tool
{
    public function name(): string { return 'list_letters'; }
    public function description(): string
    {
        return 'List issued / draft offer & appointment letters. HR + leadership only. Optionally filter by letter_type or a search string.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'letter_type' => ['type' => 'string', 'enum' => ['offer', 'appointment']],
                'search' => ['type' => 'string', 'description' => 'Substring match on recipient name.'],
            ],
            'additionalProperties' => false,
        ];
    }
    // Gated by the 'letters' feature (FEATURE_MAP). LetterController re-checks.
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/letters', $args, $user);
    }
}
