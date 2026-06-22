<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = [Role::SLUG_CEO, Role::SLUG_CMO, Role::SLUG_COO, Role::SLUG_CFO];

        foreach ($slugs as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                Permission::firstOrCreate([
                    'role_id' => $role->id,
                    'permission' => 'feature.mission',
                ]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('permission', 'feature.mission')->delete();
    }
};
