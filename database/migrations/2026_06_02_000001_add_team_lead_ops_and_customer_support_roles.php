<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds "Team Lead - Operations" and "Customer Support Executive" to the
     * roles table so they are selectable in the "Add New Team Member" Role
     * dropdown (it is live from Role::orderBy('name') in EmployeeController).
     *
     * Both are intentionally bare roles (no permission rows): RoleMiddleware
     * defaults unknown slugs to "/", and DashboardController fallback-grants
     * the basic-employee features (tasks, profile, leave, holidays, my_score,
     * schedule, archives, rewards) — a working least-privilege portal. Both
     * slugs are also added to Role::IC_SLUGS so project-scoped views resolve
     * them to "see only their own data" rather than an empty set.
     */
    private array $roles = [
        ['name' => 'Team Lead-Operations', 'slug' => 'team_lead_operations'],
        ['name' => 'Customer Support Executive', 'slug' => 'customer_support_executive'],
    ];

    public function up(): void
    {
        foreach ($this->roles as $role) {
            if (! DB::table('roles')->where('slug', $role['slug'])->exists()) {
                DB::table('roles')->insert([
                    'name' => $role['name'],
                    'slug' => $role['slug'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('slug', array_column($this->roles, 'slug'))
            ->delete();
    }
};
