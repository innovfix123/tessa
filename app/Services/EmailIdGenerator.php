<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\User;

/**
 * Generates a new hire's @innovfix.in login id from their name, with a
 * deterministic collision ladder checked against BOTH existing users and other
 * candidates' already-generated ids (so two pending hires never collide).
 *
 *   firstname → firstname.lastinitial → firstinitial.lastname → firstname{n}
 *
 * The id is a PROPOSAL — Yuvanesh is the human backstop for true Google
 * Workspace collisions Tessa can't see.
 */
class EmailIdGenerator
{
    private const DOMAIN = '@innovfix.in';

    /** @return array{email:string,strategy:string} */
    public function generate(string $fullName): array
    {
        $parts = array_values(array_filter(array_map(
            fn ($p) => preg_replace('/[^a-z0-9]/', '', mb_strtolower($p)),
            preg_split('/\s+/', trim($fullName)) ?: []
        )));
        $first = $parts[0] ?? 'newhire';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';

        $ladder = [[$first, 'firstname']];
        if ($last !== '') {
            $ladder[] = [$first . '.' . substr($last, 0, 1), 'initials'];
            $ladder[] = [substr($first, 0, 1) . '.' . $last, 'initials'];
        }
        foreach ($ladder as [$local, $strategy]) {
            $email = $local . self::DOMAIN;
            if (! $this->taken($email)) {
                return ['email' => $email, 'strategy' => $strategy];
            }
        }
        for ($n = 2; $n <= 99; $n++) {
            $email = $first . $n . self::DOMAIN;
            if (! $this->taken($email)) {
                return ['email' => $email, 'strategy' => 'custom'];
            }
        }
        return ['email' => $first . '.' . substr(md5($fullName), 0, 4) . self::DOMAIN, 'strategy' => 'custom'];
    }

    private function taken(string $email): bool
    {
        return User::where('email', $email)->exists()
            || Candidate::where('generated_email', $email)->exists();
    }
}
