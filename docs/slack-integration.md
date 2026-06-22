# Slack OAuth Integration — Deployment Guide

Last updated: April 2026

---

## What This Does

Lets every Tessa user connect their own Slack account. Once connected, Tessa can read their messages, send messages, search, manage files and reactions — all through Slack's API using their personal token.

---

## Deployment Steps (Go Live Checklist)

### Step 1: Run Database Migration

This adds 8 new columns to the `users` table. Run on the **production server**:

```bash
php artisan migrate
```

**What it adds to the `users` table:**

| Column | Type | Purpose |
|--------|------|---------|
| `slack_user_id` | VARCHAR(50), unique | Slack member ID (e.g., U01ABC123) |
| `slack_access_token` | TEXT | Encrypted OAuth token |
| `slack_refresh_token` | TEXT | Encrypted refresh token |
| `slack_team_id` | VARCHAR(50), indexed | Slack workspace ID |
| `slack_team_name` | VARCHAR(255) | Workspace display name |
| `slack_scopes` | TEXT | Comma-separated granted permissions |
| `slack_connected_at` | TIMESTAMP | When user connected Slack |
| `slack_token_expires_at` | TIMESTAMP | Token expiry time |

All columns are **nullable** — existing users won't break. Tokens are **auto-encrypted** in the database using Laravel's APP_KEY.

**If you need to run the SQL manually** (without artisan), here's the raw SQL:

```sql
ALTER TABLE `users`
    ADD COLUMN `slack_user_id` VARCHAR(50) NULL AFTER `college_id_path`,
    ADD COLUMN `slack_access_token` TEXT NULL AFTER `slack_user_id`,
    ADD COLUMN `slack_refresh_token` TEXT NULL AFTER `slack_access_token`,
    ADD COLUMN `slack_team_id` VARCHAR(50) NULL AFTER `slack_refresh_token`,
    ADD COLUMN `slack_team_name` VARCHAR(255) NULL AFTER `slack_team_id`,
    ADD COLUMN `slack_scopes` TEXT NULL AFTER `slack_team_name`,
    ADD COLUMN `slack_connected_at` TIMESTAMP NULL AFTER `slack_scopes`,
    ADD COLUMN `slack_token_expires_at` TIMESTAMP NULL AFTER `slack_connected_at`,
    ADD UNIQUE INDEX `users_slack_user_id_unique` (`slack_user_id`),
    ADD INDEX `users_slack_team_id_index` (`slack_team_id`);
```

### Step 2: Set Environment Variables

Add these to `.env` on the **production server**:

```env
# Slack OAuth (per-user)
SLACK_CLIENT_ID=9073332269078.10695286867379
SLACK_CLIENT_SECRET=a7b3c30e343ceb06f0290c7c8e9e2e12
SLACK_REDIRECT_URI=https://tessa.innovfix.ai/api/slack/callback
```

Then clear the config cache:

```bash
php artisan config:clear
```

### Step 3: Slack App Configuration (api.slack.com)

This is already done, but for reference:

1. Go to https://api.slack.com/apps and select the Tessa app
2. **OAuth & Permissions** > Redirect URLs must include:
   ```
   https://tessa.innovfix.ai/api/slack/callback
   ```
3. **User Token Scopes** must include:
   - `channels:read`, `channels:history`, `channels:write`
   - `chat:write`
   - `im:read`, `im:history`
   - `groups:read`, `groups:history`, `groups:write`
   - `mpim:read`, `mpim:history`
   - `files:read`, `files:write`
   - `reactions:read`, `reactions:write`
   - `users:read`, `users:read.email`
   - `search:read`

### Step 4: Deploy Code

Pull the branch and merge:

```bash
git pull origin feature/slack-oauth-integration
```

### Step 5: Verify

After deployment, check:

```bash
# Routes registered?
php artisan route:list --path=slack

# Columns exist?
php artisan tinker --execute="echo implode(', ', array_filter(Schema::getColumnListing('users'), fn(\$c) => str_starts_with(\$c, 'slack_')));"
```

Expected output: `slack_user_id, slack_access_token, slack_refresh_token, slack_team_id, slack_team_name, slack_scopes, slack_connected_at, slack_token_expires_at`

---

## How Users Connect Slack

1. User logs into Tessa
2. Goes to **Profile** (sidebar)
3. Scrolls to **Integrations** section
4. Clicks **Connect Slack** (purple button)
5. Redirected to Slack authorization page
6. Clicks **Allow**
7. Redirected back to Tessa — connected!

To disconnect: Same page, click **Disconnect** (red button).

---

## API Endpoints

All endpoints require Tessa login. Slack API endpoints also require the user to have connected their Slack.

### OAuth Flow

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/slack/connect` | Returns Slack OAuth URL |
| GET | `/api/slack/callback` | Handles OAuth callback (don't call directly) |
| POST | `/api/slack/disconnect` | Revokes token and disconnects |
| GET | `/api/slack/status` | Check if user's Slack is connected |

### Channels

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/slack/channels` | List all channels |
| GET | `/api/slack/channels/{id}` | Get channel info |
| GET | `/api/slack/channels/{id}/history` | Read channel messages |
| GET | `/api/slack/channels/{id}/threads/{ts}` | Read thread replies |

### Messages

| Method | Endpoint | Body | Purpose |
|--------|----------|------|---------|
| POST | `/api/slack/messages` | `channel_id`, `text` | Send a message |
| PUT | `/api/slack/messages` | `channel_id`, `ts`, `text` | Edit a message |
| DELETE | `/api/slack/messages` | `channel_id`, `ts` | Delete a message |

### DMs & Group DMs

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/slack/dms` | List DM conversations |
| GET | `/api/slack/dms/{id}/history` | Read DM messages |
| GET | `/api/slack/group-dms` | List group DM conversations |
| POST | `/api/slack/dms/open` | Open a DM with a user (`user_id`) |

### Search

| Method | Endpoint | Params | Purpose |
|--------|----------|--------|---------|
| GET | `/api/slack/search/messages` | `q` (required) | Search messages |
| GET | `/api/slack/search/files` | `q` (required) | Search files |

### Users

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/slack/users` | List all workspace users |
| GET | `/api/slack/users/{id}` | Get user info |
| GET | `/api/slack/users/{id}/presence` | Check if user is online |

### Files

| Method | Endpoint | Body | Purpose |
|--------|----------|------|---------|
| GET | `/api/slack/files` | — | List files |
| POST | `/api/slack/files` | `channel_id`, `content`, `filename` | Upload a file |

### Reactions

| Method | Endpoint | Body | Purpose |
|--------|----------|------|---------|
| POST | `/api/slack/reactions` | `channel_id`, `timestamp`, `name` | Add reaction |
| DELETE | `/api/slack/reactions` | `channel_id`, `timestamp`, `name` | Remove reaction |

---

## Files Added / Changed

| File | What |
|------|------|
| `database/migrations/2026_04_12_100001_add_slack_oauth_to_users_table.php` | Migration — adds 8 columns to users |
| `app/Services/SlackUserService.php` | Per-user Slack API client (30+ methods) |
| `app/Http/Controllers/Api/Slack/SlackController.php` | OAuth flow + API proxy endpoints |
| `app/Http/Middleware/EnsureSlackConnected.php` | Guards Slack API routes |
| `routes/api/slack.php` | 24 route definitions |
| `app/Models/User.php` | Added slack fields, encrypted casts, helpers |
| `bootstrap/app.php` | Registered `slack.connected` middleware |
| `config/services.php` | Added `slack.oauth` config section |
| `routes/api.php` | Included slack routes |
| `public/js/hr-portal.js` | Connect/Disconnect UI in profile page |

---

## Troubleshooting

**"Slack not connected" error (403)**
- User hasn't connected their Slack yet. They need to go to Profile > Connect Slack.

**"Slack token invalid" error (401)**
- User's token was revoked from Slack settings. They need to reconnect.

**OAuth callback fails**
- Check `.env` has correct `SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET`, `SLACK_REDIRECT_URI`.
- Redirect URI must match exactly in both `.env` and Slack app settings.
- Must be HTTPS.

**Columns missing after deployment**
- Run `php artisan migrate` or execute the raw SQL above manually.

**Config not loading after .env change**
- Run `php artisan config:clear` to clear cached config.

---

## Security Notes

- Tokens are **encrypted at rest** using Laravel's `encrypted` cast (AES-256-CBC via APP_KEY)
- Tokens are **never exposed in API responses** (added to `$hidden` on User model)
- OAuth uses **state parameter** for CSRF protection
- Each user can only access their own Slack data
- Disconnecting revokes the token on Slack's side too
- If APP_KEY is rotated, all users must reconnect Slack (tokens become unreadable)
