<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $featureRoles = ['ceo', 'cmo', 'content_lead', 'content_creator', 'video_editor', 'graphic_designer', 'social_media'];
        $generateRoles = ['cmo', 'content_lead', 'content_creator', 'video_editor', 'graphic_designer', 'social_media'];
        $now = now();

        foreach ($featureRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (! $role) {
                continue;
            }
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'feature.scripts')
                ->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'feature.scripts',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ($generateRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (! $role) {
                continue;
            }
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'scripts.generate')
                ->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'scripts.generate',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('permission', 'feature.scripts')->delete();
        DB::table('permissions')->where('permission', 'scripts.generate')->delete();
    }
};
