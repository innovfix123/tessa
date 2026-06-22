<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Birthday celebration (mirrors DashboardController::roleConfig so the
        // admin console wishes people too). month-day only — birth year is
        // never sent to the client.
        $todayMd = Carbon::now('Asia/Kolkata')->format('m-d');
        $todaysBirthdays = User::where('is_active', true)
            ->whereNotIn('id', config('birthday_exclusions.user_ids', [])) // opted-out of birthday surfaces
            ->whereNotNull('date_of_birth')
            ->get(['id', 'name', 'date_of_birth'])
            ->filter(fn ($b) => $b->date_of_birth->format('m-d') === $todayMd)
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values()
            ->all();

        $config = [
            'userName' => $user->name ?? '',
            'userId' => $user->id,
            'roleName' => $user->roleRelation?->name ?? 'Admin',
            'title' => 'Admin Dashboard',
            'todaysBirthdays' => $todaysBirthdays,
            'myBirthday' => [
                'is' => $user->date_of_birth
                    && $user->date_of_birth->format('m-d') === $todayMd
                    && ! in_array($user->id, config('birthday_exclusions.user_ids', []), true),
                'name' => $user->name,
            ],
        ];

        return view('admin.dashboard', compact('config'));
    }
}
