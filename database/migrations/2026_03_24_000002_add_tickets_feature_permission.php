<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $allRoles = [
            'ceo', 'coo', 'cmo', 'cfo', 'ops', 'marketing', 'growth_manager',
            'video_editor', 'graphic_designer', 'content_lead', 'content_creator',
            'social_media', 'hr', 'product_manager', 'technical_support', 'accountant',
            'tech_lead', 'qa_analyst', 'full_stack_developer', 'gen_ai_developer',
            'data_analyst',
        ];
        $now = now();

        foreach ($allRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (! $role) {
                continue;
            }
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'feature.tickets')
                ->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'feature.tickets',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('permission', 'feature.tickets')->delete();
    }
};
