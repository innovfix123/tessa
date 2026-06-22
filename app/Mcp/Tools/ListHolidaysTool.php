<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListHolidaysTool extends Tool
{
    public function name(): string { return 'list_holidays'; }
    public function description(): string
    {
        return 'List the company holiday calendar (date → holiday name) for the year.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // No API route — the dashboard injects config('holidays') directly.
        $holidays = [];
        foreach ((array) config('holidays', []) as $date => $name) {
            $holidays[] = ['date' => $date, 'name' => $name];
        }
        return ['holidays' => $holidays];
    }
}
