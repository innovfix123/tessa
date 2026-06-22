<?php

namespace App\Console\Commands;

use App\Models\OauthAccessToken;
use App\Models\OauthRefreshToken;
use Illuminate\Console\Command;

class OauthRevokeToken extends Command
{
    protected $signature = 'oauth:revoke-token {id : Access token id (from oauth:list-tokens or the audit log)}';

    protected $description = 'Revoke a single MCP OAuth access token (and the refresh token paired with it).';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $token = OauthAccessToken::with('user', 'client')->find($id);
        if (! $token) {
            $this->error("OAuth access token #{$id} not found.");
            return self::FAILURE;
        }
        if ($token->isRevoked()) {
            $this->info("Token #{$id} was already revoked at {$token->revoked_at}.");
            return self::SUCCESS;
        }

        $token->forceFill(['revoked_at' => now()])->save();
        OauthRefreshToken::where('access_token_id', $token->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->info(sprintf(
            'Revoked access token #%d for %s <%s> (client: %s).',
            $token->id,
            $token->user?->name ?? 'unknown',
            $token->user?->email ?? 'unknown',
            $token->client?->client_name ?? 'unknown',
        ));
        return self::SUCCESS;
    }
}
