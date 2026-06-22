<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create "AI Platform" department if it doesn't exist
        $aiPlatformDept = DB::table('departments')->where('slug', 'ai-platform')->first();
        if (! $aiPlatformDept) {
            $aiPlatformDeptId = DB::table('departments')->insertGetId([
                'name' => 'AI Platform',
                'slug' => 'ai-platform',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $aiPlatformDeptId = $aiPlatformDept->id;
        }

        // 2. Create "Unman" project if it doesn't exist
        $unmanProject = DB::table('projects')->where('name', 'Unman')->first();
        if (! $unmanProject) {
            $unmanProjectId = DB::table('projects')->insertGetId([
                'name' => 'Unman',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $unmanProjectId = $unmanProject->id;
        }

        // 3. Create "Tessa" project if it doesn't exist
        $tessaProject = DB::table('projects')->where('name', 'Tessa')->first();
        if (! $tessaProject) {
            $tessaProjectId = DB::table('projects')->insertGetId([
                'name' => 'Tessa',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $tessaProjectId = $tessaProject->id;
        }

        // 4. Update Ranjini: department → AI Platform, add Unman project
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if ($ranjini) {
            $ranjini->update(['department_id' => $aiPlatformDeptId]);

            if (! DB::table('project_assignments')->where('user_id', $ranjini->id)->where('project_id', $unmanProjectId)->exists()) {
                DB::table('project_assignments')->insert([
                    'user_id' => $ranjini->id,
                    'project_id' => $unmanProjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Fida: remove Hima, add Tessa
        $fida = User::where('email', 'fida@innovfix.in')->first();
        $himaProjectId = DB::table('projects')->where('name', 'Hima')->value('id');

        if ($fida) {
            if ($himaProjectId) {
                DB::table('project_assignments')
                    ->where('user_id', $fida->id)
                    ->where('project_id', $himaProjectId)
                    ->delete();
            }

            if (! DB::table('project_assignments')->where('user_id', $fida->id)->where('project_id', $tessaProjectId)->exists()) {
                DB::table('project_assignments')->insert([
                    'user_id' => $fida->id,
                    'project_id' => $tessaProjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        $fida = User::where('email', 'fida@innovfix.in')->first();
        $unmanProjectId = DB::table('projects')->where('name', 'Unman')->value('id');
        $tessaProjectId = DB::table('projects')->where('name', 'Tessa')->value('id');
        $himaProjectId = DB::table('projects')->where('name', 'Hima')->value('id');
        $operationsDeptId = DB::table('departments')->where('slug', 'operations')->value('id');

        if ($ranjini) {
            $ranjini->update(['department_id' => $operationsDeptId]);
            if ($unmanProjectId) {
                DB::table('project_assignments')
                    ->where('user_id', $ranjini->id)
                    ->where('project_id', $unmanProjectId)
                    ->delete();
            }
        }

        if ($fida) {
            if ($tessaProjectId) {
                DB::table('project_assignments')
                    ->where('user_id', $fida->id)
                    ->where('project_id', $tessaProjectId)
                    ->delete();
            }
            if ($himaProjectId && ! DB::table('project_assignments')->where('user_id', $fida->id)->where('project_id', $himaProjectId)->exists()) {
                DB::table('project_assignments')->insert([
                    'user_id' => $fida->id,
                    'project_id' => $himaProjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
