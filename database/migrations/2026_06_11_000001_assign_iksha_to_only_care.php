<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Iksha H S (#53) joins the Only Care project (#5).
 *
 * Daily Reports renders the project(s) a person is assigned to in the braces after
 * their name ("Iksha H S (Only Care)"). Those braces read $user->projects — the
 * project_assignments pivot — which was empty for Iksha, so it showed "()". Her
 * Agile access map (AgileService::allowedProjectIds) already pins her to Only Care
 * (53 => [5]); this just records the same fact in the pivot so it surfaces.
 *
 * project_assignments has a UNIQUE(user_id, project_id); insertOrIgnore keeps this
 * idempotent. user_id is a signed INT FK to users.id.
 */
return new class extends Migration
{
    private const IKSHA = 53;
    private const ONLY_CARE = 5;

    public function up(): void
    {
        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::IKSHA,
            'project_id' => self::ONLY_CARE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('project_assignments')
            ->where('user_id', self::IKSHA)
            ->where('project_id', self::ONLY_CARE)
            ->delete();
    }
};
