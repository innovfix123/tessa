<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create projects table
        if (!Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->timestamps();
            });
        }

        // 2. Create roles table
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 50)->unique();
                $table->timestamps();
            });
        }

        // 3. Add new columns to users if not present (use unsignedInteger to match roles.id; reporting_manager_id matches users.id which is INT)
        if (!Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id')->nullable()->after('name');
            });
        }
        if (!Schema::hasColumn('users', 'reporting_manager_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('reporting_manager_id')->nullable()->after('role_id');
            });
        }

        // 4. Populate roles (if empty)
        if (DB::table('roles')->count() === 0) {
            $roles = [
                ['name' => 'CEO', 'slug' => 'ceo'],
                ['name' => 'COO', 'slug' => 'coo'],
                ['name' => 'CMO', 'slug' => 'cmo'],
                ['name' => 'CFO', 'slug' => 'cfo'],
                ['name' => 'Operations Manager', 'slug' => 'ops'],
                ['name' => 'Performance Marketing', 'slug' => 'marketing'],
                ['name' => 'Product Manager (Sudar)', 'slug' => 'sudar_pm'],
                ['name' => 'Product Manager (Thedal)', 'slug' => 'thedal_pm'],
            ];
            foreach ($roles as $r) {
                DB::table('roles')->insert(array_merge($r, ['created_at' => now(), 'updated_at' => now()]));
            }
        }

        // 5. Populate projects (if empty)
        if (DB::table('projects')->count() === 0) {
            DB::table('projects')->insert([
                ['name' => 'Hima', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Sudar', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Thedal', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // 6. Map old role string to role_id (only if role column still exists)
        if (Schema::hasColumn('users', 'role')) {
            $roleMap = [
                'ceo' => 1, 'coo' => 2, 'cmo' => 3, 'cfo' => 4,
                'ops' => 5, 'marketing' => 6, 'sudar_pm' => 7, 'thedal_pm' => 8,
            ];
            foreach (DB::table('users')->get() as $user) {
                $roleId = $roleMap[$user->role] ?? null;
                if ($roleId) {
                    DB::table('users')->where('id', $user->id)->update(['role_id' => $roleId]);
                }
            }
        }

        // 7. Set reporting_manager_id
        DB::table('users')->whereIn('id', [2, 3, 4])->update(['reporting_manager_id' => 1]); // Bala, Nandha, Ayush -> JP
        DB::table('users')->where('id', 5)->update(['reporting_manager_id' => 2]);   // Sneha -> Bala
        DB::table('users')->where('id', 11)->update(['reporting_manager_id' => 3]);  // Anirudh -> Nandha
        DB::table('users')->where('id', 12)->update(['reporting_manager_id' => 2]); // Tamil -> Bala
        DB::table('users')->where('id', 13)->update(['reporting_manager_id' => 2]); // Dhanush -> Bala
        // User 10 (duplicate Sneha) - deactivate, point to Bala for consistency
        DB::table('users')->where('id', 10)->update(['reporting_manager_id' => 2, 'role_id' => 5]);

        // 8. Make role_id non-nullable, add FKs, drop old role column
        DB::table('users')->whereNull('role_id')->update(['role_id' => 1]);
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });
        // reporting_manager_id must match users.id type (INT signed) - alter if needed
        if (Schema::hasColumn('users', 'reporting_manager_id')) {
            DB::statement('ALTER TABLE users MODIFY reporting_manager_id INT NULL');
        }
        $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reporting_manager_id' AND REFERENCED_TABLE_NAME IS NOT NULL", [DB::getDatabaseName()]);
        if (empty($fks)) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('reporting_manager_id')->references('id')->on('users')->nullOnDelete();
            });
        }
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        // 9. Create project_assignments (user_id must match users.id which is INT signed)
        if (!Schema::hasTable('project_assignments')) {
        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        }

        // 10. Populate project_assignments (if empty)
        if (Schema::hasTable('project_assignments') && DB::table('project_assignments')->count() === 0) {
        $assignments = [
            [1, 1], [1, 2], [1, 3],  // JP - all
            [2, 1], [2, 2], [2, 3],  // Bala - all
            [3, 1], [3, 2], [3, 3],  // Nandha - all
            [4, 1], [4, 2], [4, 3],  // Ayush - all
            [5, 1],   // Sneha - Hima
            [11, 1],  // Anirudh - Hima
            [12, 2],  // Tamil - Sudar
            [13, 3],  // Dhanush - Thedal
        ];
        if (DB::table('users')->where('id', 10)->exists()) {
            $assignments[] = [10, 1]; // Duplicate Sneha - Hima
        }
        foreach ($assignments as [$uid, $pid]) {
            if (DB::table('users')->where('id', $uid)->exists()) {
                DB::table('project_assignments')->insert([
                    'user_id' => $uid, 'project_id' => $pid,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_assignments');
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 50)->after('name');
        });
        // Restore role from role_id (simplified)
        $slugToRole = [
            1 => 'ceo', 2 => 'coo', 3 => 'cmo', 4 => 'cfo',
            5 => 'ops', 6 => 'marketing', 7 => 'sudar_pm',
        ];
        foreach (DB::table('users')->get() as $u) {
            DB::table('users')->where('id', $u->id)->update(['role' => $slugToRole[$u->role_id] ?? 'ceo']);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['reporting_manager_id']);
            $table->dropColumn(['role_id', 'reporting_manager_id']);
        });
        Schema::dropIfExists('roles');
        Schema::dropIfExists('projects');
    }
};
