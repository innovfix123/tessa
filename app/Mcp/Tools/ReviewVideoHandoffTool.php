<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use App\Services\VideoHandoffNotifier;
use Illuminate\Http\Request;

class ReviewVideoHandoffTool extends Tool
{
    public function name(): string { return 'review_video_handoff'; }
    public function description(): string
    {
        return 'As the content creator who uploaded a raw video, review the reworked deliverable: approve it (terminal) or request changes with feedback (which notifies the editor to re-upload). All three ratios must be reworked first.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'raw_upload_id' => ['type' => 'integer', 'description' => 'The raw video upload id being reviewed.'],
                'verdict' => ['type' => 'string', 'enum' => ['approved', 'changes_requested']],
                'feedback' => ['type' => 'string', 'description' => 'Required when verdict is changes_requested.'],
            ],
            'required' => ['raw_upload_id', 'verdict'],
            'additionalProperties' => false,
        ];
    }

    // Only content creators review their own videos (controller re-checks ownership).
    public function isAvailableTo(User $user): bool
    {
        return VideoHandoffNotifier::isCreator((int) $user->id);
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $payload = [
            'action' => 'review',
            'raw_upload_id' => $args['raw_upload_id'],
            'verdict' => $args['verdict'],
        ];
        if (isset($args['feedback'])) {
            $payload['feedback'] = $args['feedback'];
        }
        return ApiSubRequest::post('/video-handoffs', $payload, $user);
    }
}
