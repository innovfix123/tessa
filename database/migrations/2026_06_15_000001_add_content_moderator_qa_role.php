<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds "Content Moderator & QA" to the roles table so it is selectable in the
     * "Add New Team Member" Role dropdown (it is live from Role::orderBy('name')
     * in EmployeeController). Content moderation and QA are combined into ONE role.
     *
     * Intentionally a bare role (no permission rows): RoleMiddleware defaults
     * unknown slugs to "/", and DashboardController fallback-grants the
     * basic-employee features (tasks, profile, leave, holidays, my_score,
     * schedule, archives, rewards) — a working least-privilege portal. The slug
     * is also added to Role::IC_SLUGS so project-scoped views resolve it to
     * "see only their own data" rather than an empty set.
     */
    public function up(): void
    {
        if (! DB::table('roles')->where('slug', 'content_moderator_qa')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Content Moderator & QA',
                'slug' => 'content_moderator_qa',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('slug', 'content_moderator_qa')->first();
        if ($role) {
            DB::table('permissions')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }
};
