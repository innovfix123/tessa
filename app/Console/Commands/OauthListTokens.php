<?php

namespace App\Console\Commands;

use App\Models\OauthAccessToken;
use App\Models\User;
use Illuminate\Console\Command;

class OauthListTokens extends Command
{
    protected $signature = 'oauth:list-tokens
        {--email= : Filter to one user by email}
        {--include-revoked : Show revoked / expired tokens too}';

    protected $description = 'List MCP OAuth access tokens, optionally filtered by user.';

    public function handle(): int
    {
        $query = OauthAccessToken::with('user', 'client')->orderByDesc('created_at');

        if ($email = $this->option('email')) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->error("No user found with email {$email}.");
                return self::FAILURE;
            }
            $query->where('user_id', $user->id);
        }
        if (! $this->option('include-revoked')) {
            $query->whereNull('revoked_at')->where('expires_at', '>', now());
        }

        $tokens = $query->limit(100)->get();
        if ($tokens->isEmpty()) {
            $this->info('No MCP OAuth tokens match.');
            return self::SUCCESS;
        }

        $rows = $tokens->map(fn ($t) => [
            'id' => $t->id,
            'user' => $t->user?->email ?? '—',
            'client' => $t->client?->client_name ?? '—',
            'created' => $t->created_at?->format('Y-m-d H:i'),
            'last_used' => $t->last_used_at?->format('Y-m-d H:i') ?? '—',
            'expires' => $t->expires_at?->format('Y-m-d'),
            'revoked' => $t->revoked_at?->format('Y-m-d') ?? '—',
        ])->all();

        $this->table(['ID', 'User', 'Client', 'Created', 'Last used', 'Expires', 'Revoked'], $rows);
        return self::SUCCESS;
    }
}
