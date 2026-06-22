<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class TessaRequestTool extends Tool
{
    public function name(): string { return 'tessa_request'; }
    public function description(): string
    {
        return 'Generic escape hatch for any Tessa /api endpoint not covered by a typed tool. Admin / CEO only. Writes are NOT sandboxed — POST/PUT/PATCH/DELETE will mutate real data. Always prefer a typed tool when one exists.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                'path' => ['type' => 'string', 'description' => 'Tessa API path AFTER /api, e.g. /tessa/tasks'],
                'query' => ['type' => 'object'],
                'body' => ['type' => 'object'],
            ],
            'required' => ['method', 'path'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return [Role::SLUG_ADMIN, Role::SLUG_CEO];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $method = strtoupper($args['method']);
        $path = $args['path'];
        $query = (array) ($args['query'] ?? []);
        $body = (array) ($args['body'] ?? []);

        return match ($method) {
            'GET' => ApiSubRequest::get($path, $query, $user),
            'POST' => ApiSubRequest::post($path, $body, $user, $query),
            'PUT' => ApiSubRequest::put($path, $body, $user, $query),
            'PATCH' => ApiSubRequest::patch($path, $body, $user, $query),
            'DELETE' => ApiSubRequest::delete($path, $query, $user),
        };
    }
}
