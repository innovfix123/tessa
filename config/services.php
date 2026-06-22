<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
            // Outbound DM quiet window. When `now` falls inside [from, until] in
            // the given timezone, SlackService::sendDirectMessage drops the
            // message. Use time-only values (e.g. "22:00" -> "09:00") for a
            // recurring nightly window that wraps past midnight, or full
            // datetimes for a one-off range. Leave from/until blank to disable.
            'quiet_from' => env('SLACK_QUIET_FROM'),
            'quiet_until' => env('SLACK_QUIET_UNTIL'),
            'quiet_tz' => env('SLACK_QUIET_TZ', 'Asia/Kolkata'),
        ],
        'oauth' => [
            'client_id'     => env('SLACK_CLIENT_ID'),
            'client_secret' => env('SLACK_CLIENT_SECRET'),
            'redirect_uri'  => env('SLACK_REDIRECT_URI'),
            // Pin OAuth to the app's home workspace (Innovfix). Without it Slack uses
            // whatever workspace the user's browser is signed into, and this
            // non-distributed app errors `invalid_team_for_non_distributed_app` when
            // that's a different workspace (e.g. innovfixgroup).
            'team'          => env('SLACK_TEAM_ID', 'T09259S7X2A'),
            // Start OAuth on the workspace-specific subdomain so Slack loads the
            // Innovfix context directly, even when the browser's active session is a
            // different workspace (the bare slack.com authorize URL + team hint does
            // NOT override an already-signed-in session — it just follows it).
            'workspace_url' => env('SLACK_WORKSPACE_URL', 'https://innovfix.slack.com'),
            'scopes'        => env('SLACK_USER_SCOPES',
                'channels:read,channels:history,channels:write,chat:write,'
                . 'im:read,im:history,groups:read,groups:history,groups:write,'
                . 'mpim:read,mpim:history,files:read,files:write,'
                . 'reactions:read,reactions:write,users:read,users:read.email,search:read'
            ),
        ],
    ],

    'google' => [
        'oauth' => [
            'client_id'         => env('GOOGLE_CLIENT_ID'),
            'client_secret'     => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'      => env('GOOGLE_REDIRECT_URI'),
            'redirect_uri_login' => env('GOOGLE_LOGIN_REDIRECT_URI'),
            // Login ("Sign in with Google") needs identity only. Keeping these
            // non-sensitive avoids Google's "unverified app" consent wall — the
            // sensitive/restricted Gmail/Calendar/Drive scopes below are
            // requested only by the explicit "Connect Google" flow.
            'login_scopes'  => env('GOOGLE_LOGIN_SCOPES', 'openid email profile'),
            'scopes'        => env('GOOGLE_SCOPES',
                'openid email profile '
                . 'https://www.googleapis.com/auth/calendar '
                . 'https://www.googleapis.com/auth/gmail.readonly '
                // gmail.compose = create/update drafts + send only (cannot read the
                // inbox). Powers HR letter email drafts; existing connections keep
                // working but must re-consent (Disconnect + Connect Google) to gain it.
                . 'https://www.googleapis.com/auth/gmail.compose '
                // drive (full) + spreadsheets = WRITE the HR documents folder + master
                // sheet via a connected HR member's token (Features 5 & 6 — no service
                // account). Internal Workspace app => no unverified-app wall. Existing
                // connections keep working (read-only) until they Disconnect + reconnect.
                . 'https://www.googleapis.com/auth/drive '
                . 'https://www.googleapis.com/auth/spreadsheets'
            ),
        ],

        // Server-side service account (Features 5 & 6) — used to WRITE the master
        // HR Google Sheet + upload documents to Drive without a logged-in user.
        // DORMANT until the JSON key exists at json_path AND the sheet/folder are
        // shared (Editor) with the service account's client_email. Sheet/folder IDs
        // default to the program's targets; only the key + sharing need provisioning.
        'service_account' => [
            'json_path' => env('GOOGLE_SERVICE_ACCOUNT_JSON', storage_path('app/google/service-account.json')),
            'sheet_id' => env('GOOGLE_HR_SHEET_ID', '1aAyyJe_SsHs88pqJLYF6K1AIRy4FScovtXyXASN-A4U'),
            'sheet_tab' => env('GOOGLE_HR_SHEET_TAB', 'Sheet1'),
            'drive_folder_id' => env('GOOGLE_HR_DRIVE_FOLDER_ID', '1NLGmXCNZwPB2-Mt-OY9PSGLTzvcs75H2'),
            // Comma-separated HR emails the per-candidate Drive folder is shared with (read-only).
            'hr_share_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_HR_SHARE_EMAILS', ''))))),
        ],

        // HR members (user IDs) whose connected Google account Tessa uses to WRITE
        // the master HR sheet + upload employee documents to Drive (Features 5 & 6,
        // via "Connect Google" — no service account). First one with a live
        // connection wins; none connected => sync stays dormant-safe.
        'hr_writer_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('GOOGLE_HR_WRITER_IDS', '45,61'))
        ))),
    ],

    'github' => [
        'oauth' => [
            'client_id'     => env('GITHUB_CLIENT_ID'),
            'client_secret' => env('GITHUB_CLIENT_SECRET'),
            'redirect_uri'  => env('GITHUB_REDIRECT_URI'),
            'scopes'        => env('GITHUB_SCOPES', 'repo,read:user,user:email'),
        ],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
    ],

    'meta' => [
        'ad_account_id' => env('META_AD_ACCOUNT_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],

    'slack_insights' => [
        // Set SLACK_INSIGHTS_DEBUG=true in .env to log per-stage pipeline tracing
        // (resolved attendees, AI returned items with confidence, drop reasons).
        // Off by default — production logs stay clean.
        'debug' => filter_var(env('SLACK_INSIGHTS_DEBUG', false), FILTER_VALIDATE_BOOLEAN),

        // Model for huddle action-item extraction. Non-Anthropic by design.
        // gemini-2.5-flash ($0.30/$2.50) — chosen explicitly (Fida, 2026-06-04):
        // cheapest + fastest of the candidates (~3.4s, well under the 45s call
        // timeout), and in the bake-off it handled the nuanced traps cleanly —
        // correct DOER vs mentioned-only, null when ownership is unclear, no
        // hallucinated assignees. Same engine as the app-wide default. Runs on
        // EVERY huddle note, so a dedup marker (see SlackInsightsService) stops
        // no-item notes from re-billing. The parser below is model-agnostic (no
        // provider response_format), so override freely with SLACK_INSIGHTS_MODEL.
        'model' => env('SLACK_INSIGHTS_MODEL', 'google/gemini-2.5-flash'),

        // Hard cap on AI extraction calls per sync run (backstop against runaway
        // fan-out). Overflow notes defer to the next 10-min cycle; the dedup marker
        // guarantees forward progress so nothing is lost. Normal post-dedup runs
        // make only a handful of calls — this just bounds the worst case.
        'max_calls_per_run' => (int) env('SLACK_INSIGHTS_MAX_CALLS_PER_RUN', 60),

        // Freshness floor for extraction: huddles older than this never reach
        // the AI. Accepts a YYYY-MM-DD date, a full datetime, or a unix epoch.
        // Use to prevent the 24h cron lookback from surfacing yesterday's items
        // when first enabling the feature. Null/empty = no floor.
        'earliest_ts' => env('SLACK_INSIGHTS_EARLIEST_TS'),

        // Dashboard age cap: only show insight cards newer than this many days
        // on the live dashboard. Older cards still appear in the Slack archive
        // view (?archive=1). Set to 0 to disable.
        'dashboard_days' => (int) env('SLACK_INSIGHTS_DASHBOARD_DAYS', 14),

        // Large-huddle fan-out narrowing: on channel/group huddles with MORE
        // than this many attendees, an unassigned action item/reminder (no
        // confident single doer) is routed to the attendees' managers (+ the
        // meeting owner) instead of every attendee — so a 15-person huddle no
        // longer puts the same card on 15 dashboards. If that leadership set is
        // empty (e.g. an owner-less ad-hoc huddle of peers with no managers) the
        // item still fans out to all attendees, so nothing is ever lost.
        // Never narrows one_on_one/scheduled huddles. Set to 0 to disable.
        'fanout_max_attendees' => (int) env('SLACK_INSIGHTS_FANOUT_MAX_ATTENDEES', 6),

        // When true, the same large-huddle narrowing also applies to shared
        // decision/follow_up items (informational, normally shown to everyone).
        // Off by default: team-wide decisions keep reaching all attendees.
        'narrow_shared_types' => filter_var(env('SLACK_INSIGHTS_NARROW_SHARED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'hima_analytics' => [
        'base_url' => env('HIMA_ANALYTICS_BASE_URL', 'https://analytics.himaapp.in'),
        'app_url' => env('HIMA_APP_URL', 'https://himaapp.in'),
        'token' => env('HIMA_ANALYTICS_TOKEN'),
        'internal_token' => env('HIMA_INTERNAL_TOKEN', env('HIMA_ANALYTICS_TOKEN')),
    ],

    'only_care' => [
        'base_url' => env('ONLYCARE_API_BASE_URL', 'https://onlycare.in'),
        'token' => env('ONLYCARE_INTERNAL_TOKEN'),
    ],

];
