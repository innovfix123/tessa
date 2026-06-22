<?php

namespace App\Console\Commands;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MintMcpToken extends Command
{
    protected $signature = 'mcp:mint {email : User email} {name : Device label, e.g. "MacBook Pro"}';

    protected $description = 'Issue a new MCP bearer token for a Tessa user';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $name = trim((string) $this->argument('name'));

        if ($email === '' || $name === '') {
            $this->error('Both email and name are required.');

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error("User {$email} is inactive.");

            return self::FAILURE;
        }

        $plain = Str::random(64);

        McpToken::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => McpToken::hashToken($plain),
        ]);

        $this->newLine();
        $this->line("MCP token issued for {$user->name} <{$user->email}> ({$name}):");
        $this->newLine();
        $this->line($plain);
        $this->newLine();
        $this->warn('Store this token now — it will not be shown again.');
        $this->line('Paste it into the user\'s claude_desktop_config.json under env.TESSA_API_TOKEN.');

        return self::SUCCESS;
    }
}
