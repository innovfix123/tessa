<?php

namespace App\Console\Commands;

use App\Models\OauthAccessToken;
use App\Models\OauthRefreshToken;
use App\Models\User;
use Illuminate\Console\Command;

class OauthRevokeUser extends Command
{
    protected $signature = 'oauth:revoke-user {email : User email}';

    protected $description = 'Emergency: revoke EVERY MCP OAuth token currently held by a user. Use when a laptop is lost or a session is suspected leaked.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");
            return self::FAILURE;
        }

        $access = OauthAccessToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        $refresh = OauthRefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->info(sprintf(
            'Revoked %d access token(s) and %d refresh token(s) for %s <%s>.',
            $access,
            $refresh,
            $user->name,
            $user->email,
        ));
        return self::SUCCESS;
    }
}
