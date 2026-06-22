<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $featureRoles = ['ceo', 'cfo', 'coo', 'cmo', 'tech_lead', 'accountant'];
        $reviewRoles = ['accountant', 'cfo', 'ceo'];

        foreach ($featureRoles as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                Permission::firstOrCreate([
                    'role_id' => $role->id,
                    'permission' => 'feature.invoices',
                ]);
            }
        }

        foreach ($reviewRoles as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                Permission::firstOrCreate([
                    'role_id' => $role->id,
                    'permission' => 'invoice.review',
                ]);
            }
        }
    }

    public function down(): void
    {
        Permission::where('permission', 'feature.invoices')->delete();
        Permission::where('permission', 'invoice.review')->delete();
    }
};
