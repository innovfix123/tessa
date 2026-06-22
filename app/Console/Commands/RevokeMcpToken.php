<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Command;

class RevokeMcpToken extends Command
{
    protected $signature = 'mcp:revoke {id : MCP token id (from mcp:list)}';

    protected $description = 'Revoke an MCP bearer token';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $token = McpToken::find($id);
        if (! $token) {
            $this->error("MCP token #{$id} not found.");

            return self::FAILURE;
        }

        if ($token->revoked_at) {
            $this->info("MCP token #{$id} was already revoked at {$token->revoked_at}.");

            return self::SUCCESS;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        $this->info("MCP token #{$id} ({$token->name}) revoked.");

        return self::SUCCESS;
    }
}
