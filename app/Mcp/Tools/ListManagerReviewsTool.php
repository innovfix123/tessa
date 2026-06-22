<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\ManagerWorkReview;
use App\Models\User;
use Illuminate\Http\Request;

class ListManagerReviewsTool extends Tool
{
    public function name(): string { return 'list_manager_reviews'; }
    public function description(): string
    {
        return 'List the Friday Work-Quality reviews you owe as a manager — the current/overdue weeks and, per week, which of your direct reports still need a rating and who is already done.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    // Visible only to managers who actually have rateable direct reports.
    public function isAvailableTo(User $user): bool
    {
        return count(ManagerWorkReview::rateableSubordinatesFor($user)) > 0;
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/manager-review', [], $user);
    }
}
