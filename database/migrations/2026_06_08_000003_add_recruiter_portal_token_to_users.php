<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Freelance recruiters reach their passwordless "open portal" (view assigned
 * JDs, upload résumés, see history + stats) through ONE permanent link
 * /r/{recruiter_portal_token}. The token is 1:1 with the recruiter user and is
 * unguessable. Backfill the existing freelance_recruiter users so their link
 * works immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recruiter_portal_token', 64)->nullable()->unique()->after('remember_token');
        });

        $roleId = DB::table('roles')->where('slug', 'freelance_recruiter')->value('id');
        if ($roleId) {
            $ids = DB::table('users')
                ->where('role_id', $roleId)
                ->whereNull('recruiter_portal_token')
                ->pluck('id');
            foreach ($ids as $id) {
                DB::table('users')->where('id', $id)->update(['recruiter_portal_token' => Str::random(48)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['recruiter_portal_token']);
            $table->dropColumn('recruiter_portal_token');
        });
    }
};
