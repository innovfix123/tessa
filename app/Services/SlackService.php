<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    private string $token;
    private string $baseUrl = 'https://slack.com/api';

    public function __construct()
    {
        $this->token = config('services.slack.notifications.bot_user_oauth_token', '');
        if (empty($this->token)) {
            Log::warning('SlackService: Empty token configured', [
                'config_key' => 'services.slack.notifications.bot_user_oauth_token',
            ]);
        }
    }

    /**
     * Resolve a meeting attendee name to a Slack user ID.
     * Strategy: name -> users table (exact/first-name) -> email -> Slack lookupByEmail
     * Fallback: {firstname}@innovfix.in -> Slack lookupByEmail
     */
    public function getUserIdByName(string $name): ?string
    {
        $email = $this->resolveEmail($name);

        if ($email) {
            return $this->lookupByEmail($email);
        }

        Log::debug('SlackService::getUserIdByName user not found', [
            'name' => $name,
        ]);
        return null;
    }

    private function resolveEmail(string $name): ?string
    {
        $trimmed = trim($name);

        $user = User::where('name', $trimmed)->first();

        if (! $user) {
            $user = User::where('name', 'LIKE', $trimmed . '%')->first();
        }

        if ($user && $user->email) {
            return $user->email;
        }

        $firstName = mb_strtolower(explode(' ', $trimmed)[0]);
        return $firstName . '@innovfix.in';
    }

    /**
     * Look up a Slack user ID by email. Results cached for 1 hour.
     */
    public function lookupByEmail(string $email): ?string
    {
        $cacheKey = 'slack_email_' . md5($email);

        return Cache::remember($cacheKey, 3600, function () use ($email) {
            try {
                $response = Http::withToken($this->token)->get("{$this->baseUrl}/users.lookupByEmail", [
                    'email' => $email,
                ]);

                if (!$response->successful()) {
                    Log::error('SlackService::lookupByEmail HTTP error', [
                        'email' => $email,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return null;
                }

                $data = $response->json();

                if (! ($data['ok'] ?? false)) {
                    Log::warning('Slack users.lookupByEmail failed', [
                        'email' => $email,
                        'error' => $data['error'] ?? 'unknown',
                    ]);
                    return null;
                }

                return $data['user']['id'] ?? null;
            } catch (\Exception $e) {
                Log::error('SlackService::lookupByEmail exception', [
                    'email' => $email,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    public function sendDirectMessage(string $slackUserId, string $text, bool $bypassQuietWindow = false): bool
    {
        if (! $bypassQuietWindow && $this->inQuietWindow()) {
            Log::info('SlackService::sendDirectMessage skipped (quiet window)', [
                'user' => $slackUserId,
            ]);
            return false;
        }

        try {
            $open = Http::withToken($this->token)->post("{$this->baseUrl}/conversations.open", [
                'users' => $slackUserId,
            ]);

            if (!$open->successful()) {
                Log::error('SlackService::sendDirectMessage HTTP error on conversations.open', [
                    'user' => $slackUserId,
                    'status' => $open->status(),
                    'body' => $open->body(),
                ]);
                return false;
            }

            $openData = $open->json();

            if (! ($openData['ok'] ?? false)) {
                Log::error('Slack conversations.open failed', [
                    'user' => $slackUserId,
                    'error' => $openData['error'] ?? 'unknown',
                ]);
                return false;
            }

            $channelId = $openData['channel']['id'];

            $msg = Http::withToken($this->token)->post("{$this->baseUrl}/chat.postMessage", [
                'channel' => $channelId,
                'text' => $text,
            ]);

            if (!$msg->successful()) {
                Log::error('SlackService::sendDirectMessage HTTP error on chat.postMessage', [
                    'channel' => $channelId,
                    'status' => $msg->status(),
                    'body' => $msg->body(),
                ]);
                return false;
            }

            $msgData = $msg->json();

            if (! ($msgData['ok'] ?? false)) {
                Log::error('Slack chat.postMessage failed', [
                    'channel' => $channelId,
                    'error' => $msgData['error'] ?? 'unknown',
                ]);
                return false;
            }

            Log::debug('SlackService::sendDirectMessage success', [
                'recipient' => $slackUserId,
                'channel' => $channelId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SlackService::sendDirectMessage exception', [
                'user' => $slackUserId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function clearUserCache(): void
    {
        $keys = Cache::get('slack_email_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget('slack_email_keys');
    }

    private function inQuietWindow(): bool
    {
        $from = config('services.slack.notifications.quiet_from');
        $until = config('services.slack.notifications.quiet_until');
        $tz = config('services.slack.notifications.quiet_tz') ?: 'Asia/Kolkata';

        if (empty($from) || empty($until)) {
            return false;
        }

        try {
            $now = Carbon::now($tz);
            $start = Carbon::parse($from, $tz);
            $end = Carbon::parse($until, $tz);

            // Same-day window (or an explicit dated one-off range): quiet when
            // now is between start and end inclusive.
            if ($start->lte($end)) {
                return $now->gte($start) && $now->lte($end);
            }

            // Overnight window that wraps past midnight (e.g. time-only
            // "22:00" -> "09:00"): quiet when now is after start OR before end.
            return $now->gte($start) || $now->lte($end);
        } catch (\Exception $e) {
            Log::warning('SlackService::inQuietWindow parse error', [
                'from' => $from,
                'until' => $until,
                'tz' => $tz,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
