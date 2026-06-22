<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'JP', 'email' => 'jp@innovfix.in', 'role_slug' => Role::SLUG_CEO, 'password' => '12345678', 'reporting_manager_id' => null],
            ['name' => 'Bala', 'email' => 'bala@innovfix.in', 'role_slug' => Role::SLUG_COO, 'password' => '12345678', 'reporting_manager_id' => 1],
            ['name' => 'Nandha', 'email' => 'nandha@innovfix.in', 'role_slug' => Role::SLUG_CMO, 'password' => '12345678', 'reporting_manager_id' => 1],
            ['name' => 'Ayush', 'email' => 'ayush@innovfix.in', 'role_slug' => Role::SLUG_CFO, 'password' => '12345678', 'reporting_manager_id' => 1],
            ['name' => 'Sneha Sunoj', 'email' => 'sneha@innovfix.in', 'role_slug' => Role::SLUG_OPS, 'password' => '12345678', 'reporting_manager_id' => 2],
            ['name' => 'Anirudh', 'email' => 'anirudh@innovfix.in', 'role_slug' => Role::SLUG_MARKETING, 'password' => '12345678', 'reporting_manager_id' => 3],
            ['name' => 'Reshma', 'email' => 'reshma@innovfix.in', 'role_slug' => Role::SLUG_TECHNICAL_SUPPORT, 'password' => '12345678', 'reporting_manager_id' => 5],
            ['name' => 'Ranjini', 'email' => 'ranjini@innovfix.in', 'role_slug' => Role::SLUG_QA_ANALYST, 'password' => '12345678', 'reporting_manager_email' => 'snehaintern@innovfix.in'],
            ['name' => 'Deeksha', 'email' => 'deeksha@innovfix.in', 'role_slug' => Role::SLUG_TECHNICAL_SUPPORT, 'password' => '12345678', 'reporting_manager_id' => 5],
            ['name' => 'Gousia', 'email' => 'gousia@innovfix.in', 'role_slug' => Role::SLUG_TECHNICAL_SUPPORT, 'password' => '12345678', 'reporting_manager_id' => 5],
            ['name' => 'Shoyab', 'email' => 'shoyab@innovfix.in', 'role_slug' => Role::SLUG_ACCOUNTANT, 'password' => '12345678', 'reporting_manager_email' => 'ayush@innovfix.in'],
            ['name' => 'Saran', 'email' => 'saran@innovfix.in', 'role_slug' => Role::SLUG_DATA_ANALYST, 'password' => '12345678', 'reporting_manager_email' => 'yuvanesh@innovfix.in'],
            ['name' => 'Admin', 'email' => 'admin@innovfix.in', 'role_slug' => Role::SLUG_ADMIN, 'password' => 'admin123', 'reporting_manager_id' => null],
        ];

        $createdUserIds = [];
        $userCount = 0;
        foreach ($users as $u) {
            $role = Role::where('slug', $u['role_slug'])->first();
            $roleId = $role?->id;

            $data = [
                'name' => $u['name'],
                'password_hash' => password_hash($u['password'], PASSWORD_BCRYPT),
                'role_id' => $roleId ?? 1,
                'is_active' => true,
            ];
            if (array_key_exists('reporting_manager_id', $u)) {
                $data['reporting_manager_id'] = $u['reporting_manager_id'];
            }
            if (array_key_exists('reporting_manager_email', $u)) {
                $data['reporting_manager_id'] = $createdUserIds[$u['reporting_manager_email']] ?? User::where('email', $u['reporting_manager_email'])->value('id');
            }

            $user = User::updateOrCreate(
                ['email' => $u['email']],
                $data
            );
            $createdUserIds[$u['email']] = $user->id;
            $userCount++;
        }

        // Ensure project assignments for team members.
        $projectAssignments = [
            'anirudh@innovfix.in' => 1,  // Hima
            'reshma@innovfix.in' => 1,   // Hima
            'deeksha@innovfix.in' => 1,  // Hima
            'gousia@innovfix.in' => 1,   // Hima
        ];
        foreach ($projectAssignments as $email => $projectId) {
            $userId = $createdUserIds[$email] ?? User::where('email', $email)->value('id');
            if ($userId && ! DB::table('project_assignments')->where('user_id', $userId)->where('project_id', $projectId)->exists()) {
                try {
                    DB::table('project_assignments')->insert([
                        'user_id' => $userId,
                        'project_id' => $projectId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('UserSeeder: Project assignment failed', [
                        'email' => $email,
                        'project_id' => $projectId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        Log::info('UserSeeder: Created/updated users', [
            'count' => $userCount,
        ]);
    }
}
