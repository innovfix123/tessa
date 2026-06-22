# Google Auth in Tessa

_Last updated: 2026-06-01_

## Setup
- **One OAuth app, type Internal** — project `tessa-498117` (project #1014639508362), owned by the **innovfix.in Workspace**.
- Internal ⇒ only `@innovfix.in` accounts can use it, and there's **no "unverified app" warning** (Workspace apps skip Google verification). Personal Gmail is blocked.
- Client ID/secret are in `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`). Config is cached → after any change run `bin/refresh-routes.sh` **and** `chown www-data:www-data .env`.

## Two flows (same client, different scopes)
| Flow | Code | Scopes | Redirect URI |
|---|---|---|---|
| **Sign in** | `AuthController::googleLogin` | `openid email profile` + `hd=innovfix.in` | `/api/auth/google/callback` |
| **Connect Gmail/Drive** | `GoogleController::connect` | `gmail.readonly drive.readonly calendar` | `/api/google/callback` |

- Login is identity-only and **must never store `google_access_token`** — that field is the only signal for `hasGoogleConnection()`; a login token would falsely mark the user Gmail-connected → Gmail scans 403.
- `hd=innovfix.in` pins Google's account chooser to the Workspace domain (UX guard; Internal is the real gate).

## Migration (2026-06-01)
- Tessa was wrongly using an **External, unverified** client from a personal **innovfix@gmail.com** project (#340212090044) → that caused the "Google hasn't verified this app" warning on **Connect Gmail**.
- Swapped `.env` to the new Internal client (#1014639508362). Old project kept for rollback.
- **All 13 connected users were disconnected** (`User::disconnectGoogle()`) so they reconnect on the new client. Each user: **Profile → Connect Google → sign in with @innovfix.in**.

## If recreating the client
- Enable **Gmail API, Drive API, Calendar API** in the project.
- Add **both** redirect URIs above to the OAuth client.
- Restricted scopes may need the Workspace admin to mark the app **Trusted** (Admin console → Security → API controls → App access control).

## Email audit (2026-06-01)
All 48 active employees' Tessa login email == their Slack-account email (Slack DM resolution by `users.email` is clean). 8 Slack accounts don't map to an active employee (1 ex-employee, a duplicate/typo, a few never onboarded) — clean up in Slack admin.
