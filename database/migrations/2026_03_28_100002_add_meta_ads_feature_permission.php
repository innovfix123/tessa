<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = [
            Role::SLUG_CEO,
            Role::SLUG_CMO,
            Role::SLUG_CFO,
            Role::SLUG_COO,
            Role::SLUG_TECH_LEAD,
            Role::SLUG_MARKETING,
            Role::SLUG_GROWTH_MANAGER,
        ];

        foreach ($slugs as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                Permission::firstOrCreate([
                    'role_id' => $role->id,
                    'permission' => 'feature.meta_ads',
                ]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('permission', 'feature.meta_ads')->delete();
    }
};
