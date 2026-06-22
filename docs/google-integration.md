# Google Integration — Deployment Guide

Last updated: April 2026

---

## What This Does

1. **Google Login** — "Log in with Google" button on login page. Users sign in with their Google account — no password needed.
2. **Gmail** — View recent emails inside Tessa.
3. **Google Calendar** — View today's events, auto-create calendar invites with Google Meet links when scheduling meetings.
4. **Google Drive** — Browse recent files directly from Tessa.
5. **Meeting Scheduler Enhancement** — Availability check now includes Google Calendar events (not just Tessa meetings).

---

## Deployment Steps

### Step 1: Run Database Migration

Adds 9 columns to `users` table.

```bash
php artisan migrate
```

**If you need raw SQL:**

```sql
ALTER TABLE `users`
    ADD COLUMN `google_user_id` VARCHAR(100) NULL AFTER `github_connected_at`,
    ADD COLUMN `google_email` VARCHAR(255) NULL AFTER `google_user_id`,
    ADD COLUMN `google_access_token` TEXT NULL AFTER `google_email`,
    ADD COLUMN `google_refresh_token` TEXT NULL AFTER `google_access_token`,
    ADD COLUMN `google_name` VARCHAR(255) NULL AFTER `google_refresh_token`,
    ADD COLUMN `google_avatar_url` VARCHAR(500) NULL AFTER `google_name`,
    ADD COLUMN `google_scopes` TEXT NULL AFTER `google_avatar_url`,
    ADD COLUMN `google_connected_at` TIMESTAMP NULL AFTER `google_scopes`,
    ADD COLUMN `google_token_expires_at` TIMESTAMP NULL AFTER `google_connected_at`,
    ADD UNIQUE INDEX `users_google_user_id_unique` (`google_user_id`);
```

### Step 2: Set Environment Variables

Add to `.env` on the production server:

```env
# Google OAuth
GOOGLE_CLIENT_ID=<your-google-client-id>
GOOGLE_CLIENT_SECRET=<your-google-client-secret>
GOOGLE_REDIRECT_URI=https://tessa.innovfix.ai/api/google/callback
GOOGLE_LOGIN_REDIRECT_URI=https://tessa.innovfix.ai/api/auth/google/callback
```

Then clear caches:

```bash
php artisan config:clear
php artisan route:clear
```

### Step 3: Google Cloud Console (Already Done)

For reference:
- **Project:** Tessa on Google Cloud Console
- **APIs enabled:** Gmail API, Google Calendar API, Google Drive API
- **OAuth Client:** Web application
- **Authorized JavaScript origins:** `https://tessa.innovfix.ai`
- **Authorized redirect URIs:**
  - `https://tessa.innovfix.ai/api/google/callback` (for integration connect)
  - `https://tessa.innovfix.ai/api/auth/google/callback` (for Google login)

### Step 4: Deploy Code

```bash
git pull origin main
```

### Step 5: Verify

```bash
php artisan route:list --path=google
php artisan route:list --path=auth/google
```

---

## Features

### Google Login (Login Page)
- "Log in with Google" button on login page
- Users click → Google OAuth → pick account → auto-login
- Matches user by `email` or `personal_email` in users table
- First Google login also auto-connects Gmail/Calendar/Drive
- If no Tessa account exists for that email → shows error

### Profile → Integrations → Connect Google
- Same as Slack/GitHub — click "Connect Google" to authorize
- Grants access to Gmail, Calendar, Drive
- Token auto-refreshes (Google tokens expire in 1 hour)

### Sidebar → Google Page (3 Tabs)

| Tab | Shows |
|-----|-------|
| **Gmail** | Recent emails — subject, from, snippet, unread indicator |
| **Calendar** | Today's events — time, title, attendees, "Join Meet" link |
| **Drive** | Recent files — name, type icon, modified date, click to open |

### Meeting Scheduler Enhancement
- `getAvailability()` now checks **both** Tessa meetings and Google Calendar events
- Google Calendar events show as "(Google)" in the availability grid
- Cancelled events are excluded
- Much more accurate availability — catches doctor appointments, external calls, personal blocks

---

## API Endpoints

### Google Login (Public — no auth required)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/auth/google` | Start Google login flow |
| GET | `/api/auth/google/callback` | Handle Google login callback |

### Google Integration (Requires auth + Google connection)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/google/connect` | Start Google OAuth for integration |
| GET | `/api/google/callback` | Handle integration callback |
| POST | `/api/google/disconnect` | Disconnect Google |
| GET | `/api/google/status` | Check connection status |
| GET | `/api/google/gmail/messages` | List Gmail messages (?q=search&max=20) |
| GET | `/api/google/gmail/messages/{id}` | Read a specific email |
| GET | `/api/google/calendar/events` | List events for a date (?date=2026-04-14) |
| POST | `/api/google/calendar/events` | Create event with Meet link |
| GET | `/api/google/drive/files` | List Drive files (?q=search) |

---

## Token Auto-Refresh

Google tokens expire in **1 hour**. The `GoogleUserService` handles this automatically:
1. Before every API call, checks `google_token_expires_at`
2. If expired → uses `google_refresh_token` to get a new access token
3. Updates DB with new token + expiry
4. If refresh fails → disconnects user (they need to reconnect)

Users never notice — it's seamless.

**Important:** When creating the OAuth credentials, `access_type=offline` and `prompt=consent` are used to ensure Google returns a refresh token.

---

## Two Redirect URIs (Why Two?)

| URI | Used For |
|-----|----------|
| `/api/google/callback` | Integration connect (Profile → Connect Google) — user already logged in |
| `/api/auth/google/callback` | Google Login — user NOT logged in, on login page |

Both must be added in Google Cloud Console → Authorized redirect URIs.

---

## Files Added / Changed

| File | What |
|------|------|
| `database/migrations/2026_04_13_100001_add_google_oauth_to_users_table.php` | Migration — 9 columns |
| `app/Services/GoogleUserService.php` | Google API client with auto token refresh |
| `app/Http/Controllers/Api/Google/GoogleController.php` | OAuth + Gmail + Calendar + Drive endpoints |
| `app/Http/Middleware/EnsureGoogleConnected.php` | Guards Google API routes |
| `routes/api/google.php` | 9 Google integration routes |
| `app/Http/Controllers/AuthController.php` | Added googleLogin() + googleLoginCallback() |
| `app/Models/User.php` | Added google fields, encrypted casts, helpers |
| `config/services.php` | Added google oauth config with login redirect |
| `bootstrap/app.php` | Registered google.connected middleware |
| `routes/api.php` | Added Google auth routes + included google routes |
| `resources/views/auth/login.blade.php` | Added "Log in with Google" button |
| `public/css/login.css` | Added Google button + divider styles |
| `public/js/hr-portal.js` | Added Connect Google in Profile |
| `public/js/portal.js` | Added Google sidebar page (Gmail/Calendar/Drive) |
| `resources/views/dashboards/portal.blade.php` | Added Google to sidebar |
| `app/Http/Controllers/DashboardController.php` | Added google to features |
| `app/Services/MeetingSchedulerService.php` | Enhanced availability with Google Calendar |

---

## Troubleshooting

**"Google login not configured"**
- Check `.env` has `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` set.

**"No Tessa account found for xyz@gmail.com"**
- The Google email must match `email` or `personal_email` in users table.
- User must be `is_active = true`.

**"Google token refresh failed"**
- User needs to reconnect Google from Profile.
- This happens if user revoked access from Google account settings.

**OAuth redirect mismatch error**
- Both redirect URIs must be added in Google Cloud Console.
- URIs must match exactly (including https, no trailing slash).

**Calendar events not showing in scheduler**
- User needs Google connected (Profile → Connect Google).
- Events only show for users who have authorized Calendar access.
