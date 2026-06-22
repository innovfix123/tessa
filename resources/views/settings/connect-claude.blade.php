@extends('layouts.app')

@section('title', 'Connect Claude')

@push('styles')
<style>
    .cc-wrap { max-width: 880px; margin: 0 auto; padding: 32px 24px 80px; color: #f5f5f5; }
    .cc-wrap h1 { font-size: 1.6rem; margin: 0 0 8px; font-weight: 600; }
    .cc-wrap p.lead { color: #a3a3a3; margin: 0 0 28px; line-height: 1.55; }
    .cc-card { background: #151515; border: 1px solid #2d2d2d; border-radius: 12px; padding: 22px 24px; margin-bottom: 18px; }
    .cc-card h2 { font-size: 1.05rem; margin: 0 0 12px; font-weight: 600; }
    .cc-card p { color: #cfcfcf; margin: 6px 0; line-height: 1.55; }
    .cc-step { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 14px; }
    .cc-step .num { flex: 0 0 28px; height: 28px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.85rem; }
    .cc-step .body { flex: 1; }
    .cc-step .body strong { color: #f5f5f5; display: block; margin-bottom: 4px; }
    .cc-step .body span { color: #a3a3a3; line-height: 1.5; }
    .cc-step .body code { background: #0a0a0a; border: 1px solid #2d2d2d; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; }
    .cc-snippet { position: relative; background: #0a0a0a; border: 1px solid #2d2d2d; border-radius: 8px; padding: 16px; margin: 12px 0 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; color: #d4d4d4; overflow-x: auto; white-space: pre; }
    .cc-copy-btn { position: absolute; top: 8px; right: 8px; background: #1f1f1f; color: #f5f5f5; border: 1px solid #2d2d2d; border-radius: 6px; padding: 4px 10px; font-size: 0.75rem; cursor: pointer; }
    .cc-copy-btn:hover { background: #2a2a2a; }
    .cc-url-row { display: flex; gap: 8px; align-items: center; background: #0a0a0a; border: 1px solid #2d2d2d; border-radius: 8px; padding: 10px 14px; margin: 14px 0; }
    .cc-url-row code { flex: 1; font-family: ui-monospace, monospace; font-size: 0.92rem; color: #93c5fd; background: transparent; padding: 0; border: 0; }
    .cc-download { display: inline-flex; align-items: center; gap: 8px; background: #3b82f6; color: white; padding: 10px 16px; border-radius: 8px; text-decoration: none; font-weight: 500; }
    .cc-download:hover { background: #2563eb; }
    .cc-download.disabled { background: #2d2d2d; color: #777; pointer-events: none; }
    .cc-download.secondary { background: transparent; color: #cfcfcf; border: 1px solid #2d2d2d; }
    .cc-download.secondary:hover { background: #1f1f1f; }
    .cc-meta { color: #777; font-size: 0.8rem; margin-top: 8px; }
    .cc-warn { background: #2a1a0a; border: 1px solid #7a4a1a; color: #fbbf24; padding: 10px 14px; border-radius: 8px; margin: 12px 0; font-size: 0.88rem; }
    .cc-back { color: #a3a3a3; text-decoration: none; font-size: 0.85rem; }
    .cc-back:hover { color: #f5f5f5; }
    details.cc-card summary { cursor: pointer; font-weight: 600; font-size: 1.05rem; }
    details.cc-card[open] summary { margin-bottom: 12px; }
    .cc-token-row { display: flex; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid #232323; }
    .cc-token-row:first-of-type { border-top: 0; }
    .cc-token-row .tname { flex: 1; }
    .cc-token-row .tname strong { display: block; color: #f5f5f5; }
    .cc-token-row .tname span { color: #777; font-size: 0.78rem; }
    .cc-disconnect { background: #2a1a1a; color: #fca5a5; border: 1px solid #7a3030; border-radius: 6px; padding: 5px 10px; font-size: 0.78rem; cursor: pointer; }
    .cc-disconnect:hover { background: #3a2020; }
    .cc-disabled-banner { background: #1a1a2a; border: 1px solid #2a3050; color: #93c5fd; padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 0.9rem; }
</style>
@endpush

@section('content')
<div class="cc-wrap">
    <a href="/" class="cc-back">&larr; Back to portal</a>
    <h1 style="margin-top:14px">Connect Claude to Tessa</h1>
    <p class="lead">Use Claude to talk to the Tessa portal in plain language &mdash; "list my open tasks", "what action items came out of yesterday's meeting", "show me the AI Squad's current sprint board". Claude will sign in as you and respect your role's permissions.</p>

    @unless ($remoteEnabled)
        <div class="cc-disabled-banner">
            <strong>Heads up:</strong> the remote connector is currently disabled (<code>MCP_REMOTE_ENABLED=false</code>). The Claude Desktop plugin in the Advanced section below still works.
        </div>
    @endunless

    {{-- Primary path: claude.ai / mobile via the remote connector. --}}
    <div class="cc-card">
        <h2>Claude.ai (web &amp; mobile)</h2>
        <p>Add Tessa as a custom connector in Claude. You'll sign in with your Tessa account once and Claude will act as you within the tools your role permits.</p>

        <div class="cc-step">
            <span class="num">1</span>
            <div class="body">
                <strong>Open Claude.ai &rarr; Settings &rarr; Connectors &rarr; Add custom connector</strong>
                <span>Or visit <a href="{{ $connectButtonUrl }}" target="_blank" rel="noopener" style="color:#3b82f6">{{ $connectButtonUrl }}</a> directly.</span>
            </div>
        </div>
        <div class="cc-step">
            <span class="num">2</span>
            <div class="body">
                <strong>Paste the Tessa URL</strong>
                <span>Name it <code>Tessa</code>. The <em>Remote MCP server URL</em> is:</span>
                <div class="cc-url-row">
                    <code id="cc-remote-url">{{ $remoteUrl }}</code>
                    <button type="button" class="cc-copy-btn" data-copy="#cc-remote-url">Copy</button>
                </div>
                <span>Leave the OAuth Client ID / Secret blank &mdash; Tessa registers Claude automatically the first time.</span>
            </div>
        </div>
        <div class="cc-step">
            <span class="num">3</span>
            <div class="body">
                <strong>Sign in with your Tessa account</strong>
                <span>Claude will pop open the Tessa login page. Use your usual Tessa email / password or Google sign-in. You'll then see a consent screen listing your role and the {{ count($availableTools) }} tools Claude will be allowed to call.</span>
            </div>
        </div>
        <div class="cc-step">
            <span class="num">4</span>
            <div class="body">
                <strong>Try it</strong>
                <span>Open a new chat and ask: <em>"What's on my Tessa plate today?"</em></span>
            </div>
        </div>

        <div class="cc-warn">Anyone who can sign into your Claude account and your Tessa account can act in Tessa as you. Revoke access from this page any time.</div>
    </div>

    {{-- Active OAuth tokens (Connected apps) --}}
    <div class="cc-card">
        <h2>Connected apps</h2>
        @if ($activeTokens->isEmpty())
            <p style="color:#777">No Claude clients are connected to your Tessa account yet.</p>
        @else
            @foreach ($activeTokens as $token)
                <div class="cc-token-row">
                    <div class="tname">
                        <strong>{{ $token->client?->client_name ?? 'MCP client' }}</strong>
                        <span>
                            Connected {{ $token->created_at?->diffForHumans() }}
                            &middot; Last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }}
                            &middot; Expires {{ $token->expires_at?->format('M j, Y') }}
                        </span>
                    </div>
                    <button class="cc-disconnect" data-revoke-token="{{ $token->id }}">Disconnect</button>
                </div>
            @endforeach
        @endif
    </div>

    {{-- Advanced: classic Claude Desktop stdio --}}
    <details class="cc-card">
        <summary>Advanced &mdash; Claude Desktop plugin (local stdio)</summary>
        <p>For users on Claude Desktop who prefer the classic local plugin. Requires an admin to mint a personal token first.</p>

        @if ($mcpbUrl)
            <p style="margin-top:14px"><a class="cc-download" href="{{ $mcpbUrl }}">Download tessa-{{ $mcpbVersion }}.mcpb</a></p>
            <div class="cc-meta">Version {{ $mcpbVersion }} &middot; drag into Claude Desktop &rarr; Settings &rarr; Extensions.</div>
        @else
            <p style="color:#777">No plugin build available yet.</p>
        @endif

        @if ($tarballUrl)
            <p style="margin-top:14px"><strong>Manual stdio install (very old Claude Desktop only):</strong></p>
            <p><a class="cc-download secondary" href="{{ $tarballUrl }}">Download tessa-mcp-server-{{ $tarballVersion }}.tgz</a></p>
            <p>Extract it, then edit your Claude Desktop config:</p>
            <p><strong>macOS:</strong> <code>~/Library/Application Support/Claude/claude_desktop_config.json</code><br>
            <strong>Windows:</strong> <code>%APPDATA%\Claude\claude_desktop_config.json</code></p>
            <div class="cc-snippet" id="cc-snippet"><button type="button" class="cc-copy-btn" data-copy="#cc-snippet">Copy</button>{{ '{
  "mcpServers": {
    "tessa": {
      "command": "node",
      "args": ["<path-to-extracted-folder>/dist/index.js"],
      "env": {
        "TESSA_BASE_URL": "' . $baseUrl . '",
        "TESSA_API_TOKEN": "<paste-token-from-admin>"
      }
    }
  }
}' }}</div>
        @endif
    </details>

    <div class="cc-card">
        <h2>Troubleshooting</h2>
        <p><strong>Claude.ai says "Failed to connect".</strong> Make sure the URL is exactly <code>{{ $remoteUrl }}</code> &mdash; no trailing slash, no missing s in https.</p>
        <p><strong>You see a 401 in Claude.</strong> Your access token expired. Click Disconnect above and re-add the connector; Claude will run the OAuth flow again.</p>
        <p><strong>A tool you expect to see isn't available.</strong> The connector filters the tool list to what your Tessa role permits. If you think you should have access, ask JP or Yuvanesh.</p>
    </div>
</div>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var selector = btn.getAttribute('data-copy');
        var node = document.querySelector(selector);
        if (!node) return;
        var text = node.innerText.replace(/^Copy\s*/, '');
        navigator.clipboard.writeText(text.trim()).then(function () {
            var prev = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = prev; }, 1200);
        });
    });
});
document.querySelectorAll('[data-revoke-token]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-revoke-token');
        if (!confirm('Disconnect this Claude client? It will need to sign in again to act in Tessa.')) return;
        fetch('/oauth/access-tokens/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        }).then(function (res) {
            if (res.ok) location.reload();
            else alert('Failed to disconnect. Please try again.');
        });
    });
});
</script>
@endsection
