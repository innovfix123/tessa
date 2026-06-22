<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                Permission::firstOrCreate([
                    'role_id' => $role->id,
                    'permission' => 'feature.employees',
                ]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('permission', 'feature.employees')->delete();
    }
};
