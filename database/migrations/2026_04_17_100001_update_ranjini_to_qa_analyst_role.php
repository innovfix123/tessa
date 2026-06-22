<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $qaRoleId = Role::where('slug', Role::SLUG_QA_ANALYST)->value('id');
        $snehaPrathapId = User::where('email', 'snehaintern@innovfix.in')->value('id');

        if (! $qaRoleId) {
            return;
        }

        DB::table('users')
            ->where('email', 'ranjini@innovfix.in')
            ->update([
                'role_id' => $qaRoleId,
                'reporting_manager_id' => $snehaPrathapId,
            ]);
    }

    public function down(): void
    {
        $techSupportRoleId = Role::where('slug', Role::SLUG_TECHNICAL_SUPPORT)->value('id');
        $snehaSunojId = User::where('email', 'sneha@innovfix.in')->value('id');

        if (! $techSupportRoleId) {
            return;
        }

        DB::table('users')
            ->where('email', 'ranjini@innovfix.in')
            ->update([
                'role_id' => $techSupportRoleId,
                'reporting_manager_id' => $snehaSunojId,
            ]);
    }
};
