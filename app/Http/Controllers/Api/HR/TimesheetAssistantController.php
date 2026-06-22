<?php

namespace App\Http\Controllers\Api\HR;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\TimesheetAssistantService;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetAssistantController extends Controller
{
    private TimesheetAssistantService $assistant;
    private TimesheetService $timesheet;

    public function __construct()
    {
        $this->assistant = app(TimesheetAssistantService::class);
        $this->timesheet = app(TimesheetService::class);
    }

    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
            'history.*.role' => 'nullable|in:user,assistant',
            'history.*.content' => 'nullable|string',
        ]);

        $caller = $request->user();
        $history = (array) $request->input('history', []);
        // Cap history to last 12 turns to bound prompt size.
        if (count($history) > 12) {
            $history = array_slice($history, -12);
        }

        $result = $this->assistant->chat($caller, (string) $request->input('message'), $history);

        return response()->json([
            'reply' => $result['reply'],
            'payload' => $result['payload'],
            'has_payload' => $result['payload'] !== null,
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'payload' => 'required|array',
            'payload.work_date' => 'required|date',
            'payload.start_time' => 'required|string',
            'payload.end_time' => 'required|string',
            'payload.description' => 'required|string|min:10',
            'payload.type' => 'required|in:regular,overtime',
            'payload.target_user' => 'nullable|string|max:120',
        ]);

        $caller = $request->user();
        $isAdmin = $caller->role === Role::SLUG_ADMIN;

        $payload = $request->input('payload');
        $targetName = trim((string) ($payload['target_user'] ?? ''));

        // Resolve target user
        if ($targetName === '' || mb_strtolower($targetName) === mb_strtolower($caller->name)
            || in_array(mb_strtolower($targetName), ['me', 'self', 'myself'], true)) {
            $target = $caller;
        } else {
            if (! $isAdmin) {
                return response()->json([
                    'error' => 'Only admins can log timesheets on behalf of others.',
                ], 403);
            }
            $target = $this->resolveUser($targetName);
            if (! $target) {
                return response()->json([
                    'error' => "Could not find a teammate matching \"{$targetName}\". Please use their full name.",
                ], 422);
            }
        }

        $loggingForSelf = $target->id === $caller->id;

        try {
            $sheet = $this->timesheet->createOrUpdate(
                $target,
                (string) $payload['work_date'],
                [[
                    'start_time' => (string) $payload['start_time'],
                    'end_time' => (string) $payload['end_time'],
                    'type' => (string) $payload['type'],
                    'description' => (string) $payload['description'],
                ]],
                // isAdmin true only when admin is logging on behalf of others (lock bypass).
                $isAdmin && ! $loggingForSelf,
                'ai'
            );

            return response()->json([
                'message' => $loggingForSelf
                    ? 'Logged your timesheet.'
                    : "Logged timesheet for {$target->name}.",
                'timesheet' => [
                    'id' => $sheet->id,
                    'user_id' => $sheet->user_id,
                    'user_name' => $target->name,
                    'work_date' => $sheet->work_date->format('Y-m-d'),
                    'total_hours' => (float) $sheet->total_hours,
                    'overtime_hours' => (float) $sheet->overtime_hours,
                    'amount' => (float) $sheet->amount,
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Resolve a free-text name to a User (exact, then prefix match).
     */
    private function resolveUser(string $name): ?User
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $user = User::where('name', $trimmed)->where('is_active', true)->first();
        if ($user) {
            return $user;
        }

        return User::where('name', 'LIKE', $trimmed . '%')
            ->where('is_active', true)
            ->first();
    }
}
