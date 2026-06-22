<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use App\Services\VideoHandoffNotifier;
use Illuminate\Http\Request;

class ListVideoHandoffsTool extends Tool
{
    public function name(): string { return 'list_video_handoffs'; }
    public function description(): string
    {
        return 'List the raw videos and their reworked deliverables for a week, with each creator/day and the review state. Optionally pass week_key (YYYY-MM-DD) to pick a week.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['week_key' => ['type' => 'string', 'description' => 'Any date in the target week (YYYY-MM-DD). Defaults to the current week.']],
            'additionalProperties' => false,
        ];
    }

    // Same audience as VideoHandoffController::canView — creators, Anaz, Krishnan, admin viewers.
    public function isAvailableTo(User $user): bool
    {
        $id = (int) $user->id;
        return $id === VideoHandoffNotifier::ANAZ_USER_ID
            || $id === VideoHandoffNotifier::KRISHNAN_USER_ID
            || in_array($id, [1, 4], true)
            || VideoHandoffNotifier::isCreator($id);
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (! empty($args['week_key'])) {
            $query['week_key'] = $args['week_key'];
        }
        return ApiSubRequest::get('/video-handoffs', $query, $user);
    }
}
