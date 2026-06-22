<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MarkRewardWithdrawalPaidTool extends Tool
{
    public function name(): string { return 'mark_reward_withdrawal_paid'; }
    public function description(): string
    {
        return 'Settle a reward withdrawal (mark it paid, optionally with a UTR number and note). Reward payer only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'withdrawal_id' => ['type' => 'integer'],
                'utr_number' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['withdrawal_id'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.payers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['withdrawal_id'];
        unset($args['withdrawal_id']);
        return ApiSubRequest::post("/rewards/withdrawals/{$id}/mark-paid", $args, $user);
    }
}
