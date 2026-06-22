<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Mcp\McpToolRegistry;
use App\Models\OauthAccessToken;
use Illuminate\Http\Request;

class ConnectClaudeController extends Controller
{
    public function show(Request $request)
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $mcpbVersion = $this->latestVersion('tessa-*.mcpb');
        $tarballVersion = $this->latestVersion('tessa-mcp-server-*.tgz');

        $user = $request->user();
        $remoteEnabled = (bool) config('mcp.remote_enabled');

        // Active OAuth access tokens issued to this user (for the
        // "Connected apps" section). Eager-loaded with the client so the
        // template can show the application name + when it was approved.
        $activeTokens = $user
            ? OauthAccessToken::with('client')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $availableTools = $user
            ? app(McpToolRegistry::class)->toolsForUser($user)
            : [];

        return view('settings.connect-claude', [
            'baseUrl'         => $baseUrl,
            'remoteEnabled'   => $remoteEnabled,
            'remoteUrl'       => rtrim((string) config('mcp.resource_url', $baseUrl.'/mcp'), '/'),
            'connectButtonUrl'=> config('mcp.connect_button_url'),
            'activeTokens'    => $activeTokens,
            'availableTools'  => $availableTools,
            'mcpbVersion'     => $mcpbVersion,
            'mcpbUrl'         => $mcpbVersion
                ? "{$baseUrl}/downloads/tessa-{$mcpbVersion}.mcpb"
                : null,
            'tarballVersion'  => $tarballVersion,
            'tarballUrl'      => $tarballVersion
                ? "{$baseUrl}/downloads/tessa-mcp-server-{$tarballVersion}.tgz"
                : null,
        ]);
    }

    private function latestVersion(string $pattern): ?string
    {
        $dir = public_path('downloads');
        if (! is_dir($dir)) {
            return null;
        }
        $matches = glob($dir.'/'.$pattern) ?: [];
        if (empty($matches)) {
            return null;
        }
        natsort($matches);
        $latest = end($matches);
        if (! is_string($latest)) {
            return null;
        }
        $base = basename($latest);
        $regex = '/^'.str_replace('\\*', '(.+)', preg_quote($pattern, '/')).'$/';
        if (preg_match($regex, $base, $m)) {
            return $m[1];
        }

        return null;
    }
}
