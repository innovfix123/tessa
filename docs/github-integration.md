# GitHub Integration — Deployment Guide

Last updated: April 2026

---

## What This Does

Lets every Tessa user connect their own GitHub account. Once connected, Tessa can view repos, branches, PRs, commits, create branches from tasks, and show code activity — all through GitHub's API using their personal token.

---

## Deployment Steps (Go Live Checklist)

### Step 1: Run Database Migrations

This adds 6 columns to `users` table and 4 columns to `tessa_tasks` table.

```bash
php artisan migrate
```

**What it adds to the `users` table:**

| Column | Type | Purpose |
|--------|------|---------|
| `github_user_id` | VARCHAR(50), unique | GitHub user ID |
| `github_username` | VARCHAR(100) | GitHub login name (@username) |
| `github_access_token` | TEXT | Encrypted OAuth token |
| `github_avatar_url` | VARCHAR(500) | Profile picture URL |
| `github_scopes` | TEXT | Granted permissions |
| `github_connected_at` | TIMESTAMP | When connected |

**What it adds to the `tessa_tasks` table:**

| Column | Type | Purpose |
|--------|------|---------|
| `github_branch` | VARCHAR(255) | Branch name linked to task |
| `github_pr_url` | VARCHAR(500) | Pull request URL |
| `github_pr_status` | VARCHAR(50) | PR status (open/merged/closed) |
| `github_repo` | VARCHAR(255) | Repository (owner/repo) |

All columns are **nullable** — existing data won't break. Token is **auto-encrypted**.

**If you need to run SQL manually:**

```sql
-- Users table
ALTER TABLE `users`
    ADD COLUMN `github_user_id` VARCHAR(50) NULL AFTER `slack_token_expires_at`,
    ADD COLUMN `github_username` VARCHAR(100) NULL AFTER `github_user_id`,
    ADD COLUMN `github_access_token` TEXT NULL AFTER `github_username`,
    ADD COLUMN `github_avatar_url` VARCHAR(500) NULL AFTER `github_access_token`,
    ADD COLUMN `github_scopes` TEXT NULL AFTER `github_avatar_url`,
    ADD COLUMN `github_connected_at` TIMESTAMP NULL AFTER `github_scopes`,
    ADD UNIQUE INDEX `users_github_user_id_unique` (`github_user_id`);

-- Tasks table
ALTER TABLE `tessa_tasks`
    ADD COLUMN `github_branch` VARCHAR(255) NULL AFTER `completed_at`,
    ADD COLUMN `github_pr_url` VARCHAR(500) NULL AFTER `github_branch`,
    ADD COLUMN `github_pr_status` VARCHAR(50) NULL AFTER `github_pr_url`,
    ADD COLUMN `github_repo` VARCHAR(255) NULL AFTER `github_pr_status`;
```

### Step 2: Set Environment Variables

Add these to `.env` on the **production server**:

```env
# GitHub OAuth (per-user)
GITHUB_CLIENT_ID=Ov23liwGbxcDI3eeqJYV
GITHUB_CLIENT_SECRET=12c46549c360acb8df922fbe7ab0435cef0fafa2
GITHUB_REDIRECT_URI=https://tessa.innovfix.ai/api/github/callback
```

Then clear the config cache:

```bash
php artisan config:clear
```

### Step 3: GitHub OAuth App (Already Done)

For reference, the OAuth App is configured at https://github.com/settings/developers:
- **App name:** Tessa
- **Homepage:** https://tessa.innovfix.ai
- **Callback URL:** https://tessa.innovfix.ai/api/github/callback
- **Scopes:** repo, read:user, user:email

### Step 4: Deploy Code

```bash
git pull origin feature/github-integration
```

### Step 5: Verify

```bash
# Routes registered?
php artisan route:list --path=github

# Columns exist?
php artisan tinker --execute="echo implode(', ', array_filter(Schema::getColumnListing('users'), fn(\$c) => str_starts_with(\$c, 'github_')));"
```

Expected: `github_user_id, github_username, github_access_token, github_avatar_url, github_scopes, github_connected_at`

---

## How Users Connect GitHub

1. Login to Tessa
2. Go to **Profile** (sidebar)
3. Scroll to **Integrations** section
4. Click **Connect GitHub** (dark button)
5. Redirected to GitHub authorization page
6. Click **Authorize**
7. Redirected back to Tessa — connected!

To disconnect: Same page, click **Disconnect** (red button).

---

## API Endpoints

### OAuth Flow

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/connect` | Returns GitHub OAuth URL |
| GET | `/api/github/callback` | Handles OAuth callback (don't call directly) |
| POST | `/api/github/disconnect` | Revokes and disconnects |
| GET | `/api/github/status` | Check connection status |

### Repositories

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/repos` | List user's repositories |

### Branches

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/repos/{owner}/{repo}/branches` | List branches |

### Pull Requests

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/repos/{owner}/{repo}/pulls` | List PRs (filter: ?state=open/closed/all) |

### Commits

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/repos/{owner}/{repo}/commits` | List commits |

### Activity

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/github/activity` | User's recent GitHub events |

### Task ↔ GitHub

| Method | Endpoint | Body | Purpose |
|--------|----------|------|---------|
| POST | `/api/github/tasks/{taskId}/create-branch` | `owner`, `repo` | Creates `feature/TSK-{id}-{title}` branch |
| GET | `/api/github/tasks/{taskId}/status` | — | Returns branch, PR, commits for task |

---

## Files Added / Changed

| File | What |
|------|------|
| `database/migrations/2026_04_12_300001_add_github_oauth_to_users_table.php` | Migration — 6 columns on users |
| `database/migrations/2026_04_12_300002_add_github_fields_to_tessa_tasks_table.php` | Migration — 4 columns on tasks |
| `app/Services/GitHubUserService.php` | GitHub API client (15+ methods) |
| `app/Http/Controllers/Api/GitHub/GitHubController.php` | OAuth + API proxy + task linking |
| `app/Http/Middleware/EnsureGitHubConnected.php` | Guards GitHub API routes |
| `routes/api/github.php` | 11 route definitions |
| `app/Models/User.php` | Added github fields, encrypted casts, helpers |
| `app/Models/TessaTask.php` | Added github_branch, pr_url, pr_status, repo |
| `config/services.php` | Added github.oauth config |
| `bootstrap/app.php` | Registered github.connected middleware |
| `routes/api.php` | Included github routes |
| `public/js/hr-portal.js` | Connect GitHub button in Profile |
| `public/js/portal.js` | GitHub sidebar page (repos, PRs, commits, branches, activity) |
| `resources/views/dashboards/portal.blade.php` | GitHub sidebar icon + section |
| `app/Http/Controllers/DashboardController.php` | Added github to features |

---

## GitHub Sidebar Page Features

### Tab: Pull Requests
- Shows open PRs with colored status badges
- PR number, title, source → target branch, author

### Tab: Commits
- Recent commits with SHA (7 char), message, author, date
- Click to view on GitHub

### Tab: Branches
- All branches with default branch highlighted
- Latest commit SHA per branch

### Tab: Activity
- Recent events: pushes, PR actions, branch creation, forks, stars
- Event icons and repo names

### Task → GitHub
- Any Tessa task can have a GitHub branch auto-created
- Branch naming: `feature/TSK-{task_id}-{slug-title}`
- PR status auto-syncs when checking task status

---

## Troubleshooting

**"GitHub not connected" error (403)**
- User needs to go to Profile > Connect GitHub.

**"GitHub token invalid" error (401)**
- Token was revoked on GitHub. User needs to reconnect.

**OAuth callback fails**
- Check `.env` has correct GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET, GITHUB_REDIRECT_URI.
- Callback URL must match exactly in both `.env` and GitHub OAuth App settings.

**"Create branch" fails**
- User needs write access to the repository on GitHub.
- The `repo` scope must be granted during OAuth.

**Columns missing after deployment**
- Run `php artisan migrate` or execute the raw SQL above.

---

## Security Notes

- Tokens are **encrypted at rest** using Laravel's `encrypted` cast
- Tokens are **never exposed in API responses** (in `$hidden` on User model)
- OAuth uses **state parameter** for CSRF protection
- Each user can only access repos they have permission for on GitHub
- GitHub tokens don't expire unless revoked by user
