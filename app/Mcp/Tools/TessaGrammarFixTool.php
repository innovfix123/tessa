<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class TessaGrammarFixTool extends Tool
{
    public function name(): string { return 'tessa_grammar_fix'; }
    public function description(): string
    {
        return 'Run text through Tessa\'s grammar correction and get the cleaned-up version.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/tessa/grammar', ['text' => $args['text']], $user);
    }
}
