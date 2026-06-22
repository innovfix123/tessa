<?php

// Configuration for the remote Tessa MCP connector (Claude.ai web/mobile,
// Streamable HTTP transport at /mcp, OAuth 2.1 + DCR auth server).
//
// The local stdio MCP server under tessa-mcp-server/ ships under a different
// path (/api/mcp/*) and is unaffected by these settings.

return [

    // Master kill switch. When false the /.well-known endpoints,
    // /oauth/* routes, and /mcp transport all 404. Set MCP_REMOTE_ENABLED=true
    // in .env once the auth tables are migrated and the server is dogfooded.
    'remote_enabled' => (bool) env('MCP_REMOTE_ENABLED', false),

    // Public URLs used in metadata documents. The resource URL is also the
    // audience (RFC 8707) baked into every issued token; mismatches cause a
    // 401 at the resource server, so don't change this casually in prod.
    'resource_url' => env('MCP_RESOURCE_URL', rtrim((string) env('APP_URL', ''), '/').'/mcp'),
    'authorization_server' => rtrim((string) env('APP_URL', ''), '/'),

    // Token TTLs. 30-day access tokens balance "users don't get logged out
    // mid-session" with "leaked tokens are bounded". Refresh tokens are
    // longer-lived because they're rotated on each use.
    'access_token_ttl_seconds' => (int) env('MCP_ACCESS_TOKEN_TTL', 30 * 24 * 60 * 60),
    'refresh_token_ttl_seconds' => (int) env('MCP_REFRESH_TOKEN_TTL', 180 * 24 * 60 * 60),
    'authorization_code_ttl_seconds' => 600, // 10 minutes; standard.

    // Allowed Origin header values for /mcp. Anthropic's documented
    // callback URL is https://claude.ai/api/mcp/auth_callback; the request
    // Origin is one of claude.ai / claude.com. Server-to-server calls from
    // the Anthropic Messages API arrive with no Origin and are allowed
    // through (verified by Bearer token instead).
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env(
        'MCP_ALLOWED_ORIGINS',
        'https://claude.ai,https://claude.com'
    )))),

    // Per-user rate limit on /mcp (calls per minute). Interactive Claude
    // sessions are chatty — list_tools + 5-15 tool calls per user turn —
    // so this is higher than the 120/min on the legacy mcp.token path.
    'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT', 240),

    // Scopes advertised in .well-known/oauth-protected-resource. Today every
    // token gets 'mcp' (everything the user's Tessa role allows). Reserved
    // for future fine-grained scoping (mcp:read, mcp:hr, etc.).
    'scopes_supported' => ['mcp'],

    // Optional Claude.ai deeplink for the "Add to Claude" button on
    // /settings/connect-claude. Leave null to fall back to copy-the-URL.
    'connect_button_url' => env(
        'MCP_CLAUDE_CONNECT_URL',
        'https://claude.ai/settings/connectors'
    ),
];
