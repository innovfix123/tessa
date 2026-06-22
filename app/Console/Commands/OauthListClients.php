<?php

namespace App\Console\Commands;

use App\Models\OauthClient;
use Illuminate\Console\Command;

class OauthListClients extends Command
{
    protected $signature = 'oauth:list-clients
        {--include-revoked : Show clients that have been revoked}';

    protected $description = 'List OAuth clients registered against the Tessa MCP authorization server.';

    public function handle(): int
    {
        $query = OauthClient::query()->orderByDesc('created_at');
        if (! $this->option('include-revoked')) {
            $query->whereNull('revoked_at');
        }
        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->info('No OAuth clients registered.');
            return self::SUCCESS;
        }

        $rows = $clients->map(fn ($c) => [
            'id' => $c->id,
            'client_id' => $c->client_id,
            'name' => $c->client_name,
            'auth' => $c->token_endpoint_auth_method,
            'via' => $c->created_via,
            'tokens' => $c->accessTokens()->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'created' => $c->created_at?->format('Y-m-d'),
            'revoked' => $c->revoked_at?->format('Y-m-d') ?? '—',
        ])->all();

        $this->table(['ID', 'client_id', 'Name', 'Auth', 'Via', 'Active tokens', 'Created', 'Revoked'], $rows);
        return self::SUCCESS;
    }
}
