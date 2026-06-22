<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * (Re)create the two freelance recruiters — Yashasvi & Rohit — as LOGIN-DISABLED
 * users. They never sign in (no password, no Google): their only entry point is
 * their permanent open-portal link /r/{recruiter_portal_token}. The random
 * password hash means no plaintext (not even the default 12345678) can log in;
 * is_active stays true only so the open-portal resolver + 👥 Recruiters list
 * accept them. Idempotent: skips an existing email, mints a token where missing.
 */
return new class extends Migration
{
    private const RECRUITERS = [
        ['name' => 'Yashasvi', 'email' => 'yashasvi.recruiter@innovfix.in'],
        ['name' => 'Rohit', 'email' => 'rohit.recruiter@innovfix.in'],
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'freelance_recruiter')->value('id');
        if (! $roleId) {
            return;
        }

        foreach (self::RECRUITERS as $r) {
            $existing = User::where('email', $r['email'])->first();
            if ($existing) {
                $existing->update([
                    'role_id' => $roleId,
                    'is_active' => true,
                    'employment_type' => 'freelancer',
                    'employee_status' => 'active',
                    'recruiter_portal_token' => $existing->recruiter_portal_token ?: Str::random(48),
                ]);
                continue;
            }

            User::create([
                'name' => $r['name'],
                'email' => $r['email'],
                // Login disabled: random, unguessable hash → no password sign-in.
                'password_hash' => password_hash(Str::random(48), PASSWORD_BCRYPT),
                'role_id' => $roleId,
                'is_active' => true,
                'employment_type' => 'freelancer',
                'employee_status' => 'active',
                'joining_date' => now()->toDateString(),
                'notice_period_days' => 15,
                'recruiter_portal_token' => Str::random(48),
            ]);
        }
    }

    public function down(): void
    {
        // Remove only if they have no candidates — never orphan pipeline data.
        foreach (self::RECRUITERS as $r) {
            $u = User::where('email', $r['email'])->first();
            if ($u && ! DB::table('candidates')->where('uploaded_by', $u->id)->exists()) {
                $u->delete();
            }
        }
    }
};
