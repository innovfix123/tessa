<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Command;

class ListMcpTokens extends Command
{
    protected $signature = 'mcp:list {email : User email}';

    protected $description = 'List MCP tokens issued for a Tessa user';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $tokens = McpToken::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        if ($tokens->isEmpty()) {
            $this->info("No MCP tokens for {$user->email}.");

            return self::SUCCESS;
        }

        $rows = $tokens->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'created_at' => $t->created_at?->toDateTimeString(),
            'last_used_at' => $t->last_used_at?->toDateTimeString() ?? '—',
            'revoked_at' => $t->revoked_at?->toDateTimeString() ?? '—',
        ])->all();

        $this->table(['ID', 'Device', 'Created', 'Last used', 'Revoked'], $rows);

        return self::SUCCESS;
    }
}
