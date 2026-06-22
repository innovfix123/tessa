# tessa-mcp-server

MCP (Model Context Protocol) server that exposes the Tessa portal to Claude
Desktop via **stdio**. Each user runs this locally on their laptop.

> **Most users should NOT use this.** The recommended path is the remote
> connector at `https://tessa.innovfix.ai/mcp` — see Settings →
> [Connect Claude](https://tessa.innovfix.ai/settings/connect-claude) in
> the portal. The remote connector handles OAuth login automatically and
> works in Claude.ai (web + mobile) as well as Claude Desktop.
>
> This stdio server still exists as a fallback for users on older
> Claude Desktop versions or air-gapped setups.

## End users

Don't build from source. Visit `/settings/connect-claude` in the Tessa portal —
it has both the remote-connector URL and the local plugin download.

## Developers

```sh
npm ci
npm run build              # tsc → dist/
npm run dev                # tsx watch (live reload)
npm run package            # build + bundle a tarball under ../public/downloads/
```

Run locally against the live API:

```sh
TESSA_BASE_URL=https://tessa.innovfix.ai \
TESSA_API_TOKEN=<token from `php artisan mcp:mint`> \
node dist/index.js
```

For an interactive sanity check:

```sh
TESSA_BASE_URL=... TESSA_API_TOKEN=... \
  npx @modelcontextprotocol/inspector node dist/index.js
```

## Tools shipped (v0.1)

Tier 1 — typed reads:

- `list_tasks`, `get_task`
- `list_meetings`, `list_action_items`
- `list_dashboard_notes`
- `list_my_kras`, `list_daily_reports`
- `list_employees`, `list_leave_requests`
- `list_squads`, `get_sprint_board`

Tier 3 — escape hatch:

- `tessa_request` — generic GET/POST/PUT/PATCH/DELETE for any `/api/mcp/*`
  endpoint. Writes are real, not sandboxed.

## Auth

Tokens are minted by an admin via `php artisan mcp:mint <email> <device-name>`,
hashed in `mcp_tokens`, sent via Bearer header. Revoke via
`php artisan mcp:revoke <id>`.

## Adding new tools

1. Drop a file under `src/tools/` exporting a `Tool[]`.
2. Add it to `src/tools/index.ts`.
3. Bump `package.json` version, run `npm run package`.
