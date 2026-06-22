<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use App\Services\VideoHandoffNotifier;
use Illuminate\Http\Request;

class DeleteVideoHandoffTool extends Tool
{
    public function name(): string { return 'delete_video_handoff'; }
    public function description(): string
    {
        return 'Delete one reworked video deliverable (this is also how "replace" works — delete, then re-upload). Editor (Anaz) only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'The reworked-video (handoff) id to delete.']],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    // Editor (Anaz) only — same gate the controller enforces.
    public function isAvailableTo(User $user): bool
    {
        return (int) $user->id === VideoHandoffNotifier::ANAZ_USER_ID;
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/video-handoffs', ['action' => 'delete', 'id' => $args['id']], $user);
    }
}
