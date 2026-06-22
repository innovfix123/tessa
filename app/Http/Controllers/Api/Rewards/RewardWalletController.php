<?php

namespace App\Http\Controllers\Api\Rewards;

use App\Http\Controllers\Controller;
use App\Services\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardWalletController extends Controller
{
    public function __construct(private RewardService $rewardService) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $balance = $this->rewardService->walletBalance($user);

        $isReviewer = in_array($user->id, config('rewards.reviewers', []), true);
        $isPayer = in_array($user->id, config('rewards.payers', []), true);
        $isPoolCreator = $this->rewardService->isPoolCreator($user);

        return response()->json([
            'balance' => $balance,
            'roles' => [
                'is_reviewer' => $isReviewer,
                'is_payer' => $isPayer,
                'is_pool_creator' => $isPoolCreator,
            ],
        ]);
    }
}
