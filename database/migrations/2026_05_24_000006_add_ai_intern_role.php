<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adds "AI Intern" to the roles table so it is selectable in the
        // "Add New Team Member" Role dropdown. A bare role is intentionally
        // safe: RoleMiddleware defaults unknown slugs to "/", and
        // DashboardController fallback-grants the basic-employee features
        // (tasks, profile, leave, holidays, my_score, slack, schedule,
        // github, google) — least privilege, appropriate for an intern.
        if (! DB::table('roles')->where('slug', 'ai_intern')->exists()) {
            DB::table('roles')->insert([
                'name' => 'AI Intern',
                'slug' => 'ai_intern',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'ai_intern')->delete();
    }
};
