<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The MCP tool registry instantiates ~60 tool classes on
        // construction; bind it as a singleton so /mcp + the consent
        // screen + the permissions command don't re-build it per request.
        $this->app->singleton(\App\Mcp\McpToolRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Production Safety Guards ──
        if ($this->app->isProduction()) {
            // Block destructive artisan commands: migrate:fresh, migrate:reset, migrate:rollback, db:wipe
            DB::prohibitDestructiveCommands();
        }
    }
}
