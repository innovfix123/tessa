<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\ManagerWorkReview;
use App\Models\User;
use Illuminate\Http\Request;

class SubmitManagerReviewTool extends Tool
{
    public function name(): string { return 'submit_manager_review'; }
    public function description(): string
    {
        return 'Submit your Friday Work-Quality ratings for a week. Rate each direct report 1-5 on deliverables and on quality. A week is locked once submitted (cannot be edited). Use list_manager_reviews to get the week_key and subordinate_ids.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week_key' => ['type' => 'string', 'description' => 'The review week, YYYY-MM-DD (the Friday or any date in that week).'],
                'items' => [
                    'type' => 'array',
                    'description' => 'One entry per subordinate.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'subordinate_id' => ['type' => 'integer'],
                            'rating_deliverables' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                            'rating_quality' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                        ],
                        'required' => ['subordinate_id', 'rating_deliverables', 'rating_quality'],
                    ],
                ],
            ],
            'required' => ['week_key', 'items'],
            'additionalProperties' => false,
        ];
    }

    public function isAvailableTo(User $user): bool
    {
        return count(ManagerWorkReview::rateableSubordinatesFor($user)) > 0;
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/manager-review', [
            'week_key' => $args['week_key'],
            'items' => $args['items'],
        ], $user);
    }
}
