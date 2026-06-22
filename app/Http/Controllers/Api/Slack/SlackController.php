<?php

namespace App\Http\Controllers\Api\Slack;

use App\Http\Controllers\Controller;
use App\Services\SlackUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SlackController extends Controller
{
    // ─── OAuth Flow ──────────────────────────────────────────────

    /** Generate Slack OAuth URL for the user to authorize. */
    public function connect(Request $request): JsonResponse
    {
        $state = Str::random(40);
        $request->session()->put('slack_oauth_state', $state);

        $config = config('services.slack.oauth');

        $params = [
            'client_id'    => $config['client_id'],
            'user_scope'   => $config['scopes'],
            'redirect_uri' => $config['redirect_uri'],
            'state'        => $state,
        ];
        // Force the app's home workspace so a user signed into a different workspace
        // (e.g. innovfixgroup) doesn't hit invalid_team_for_non_distributed_app.
        if (! empty($config['team'])) {
            $params['team'] = $config['team'];
        }

        // Route through the workspace subdomain (e.g. https://innovfix.slack.com) so
        // Slack opens the correct workspace context instead of the browser's current one.
        $base = rtrim($config['workspace_url'] ?? 'https://slack.com', '/');
        $url = $base . '/oauth/v2/authorize?' . http_build_query($params);

        // Never let the browser cache this — the URL carries a one-time CSRF state
        // and the workspace-pinned `team` param; a stale copy reintroduces the
        // wrong-workspace error.
        return response()->json(['ok' => true, 'url' => $url])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /** Handle Slack OAuth callback — exchange code for user token. */
    public function callback(Request $request): RedirectResponse
    {
        $baseUrl = config('app.url');

        // Validate state
        $expectedState = $request->session()->pull('slack_oauth_state');
        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect($baseUrl . '/?slack=error&reason=invalid_state');
        }

        // User denied
        if ($request->query('error')) {
            return redirect($baseUrl . '/?slack=error&reason=access_denied');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect($baseUrl . '/?slack=error&reason=missing_code');
        }

        try {
            $config = config('services.slack.oauth');

            $response = Http::asForm()->post('https://slack.com/api/oauth.v2.access', [
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $config['redirect_uri'],
            ]);

            $data = $response->json();

            if (! ($data['ok'] ?? false)) {
                Log::error('Slack OAuth exchange failed', ['error' => $data['error'] ?? 'unknown']);
                return redirect($baseUrl . '/?slack=error&reason=exchange_failed');
            }

            $authedUser = $data['authed_user'] ?? [];
            $team       = $data['team'] ?? [];

            $request->user()->update([
                'slack_user_id'          => $authedUser['id'] ?? null,
                'slack_access_token'     => $authedUser['access_token'] ?? null,
                'slack_refresh_token'    => $authedUser['refresh_token'] ?? null,
                'slack_team_id'          => $team['id'] ?? null,
                'slack_team_name'        => $team['name'] ?? null,
                'slack_scopes'           => $authedUser['scope'] ?? null,
                'slack_connected_at'     => now(),
                'slack_token_expires_at' => isset($authedUser['expires_in'])
                    ? now()->addSeconds($authedUser['expires_in'])
                    : null,
            ]);

            return redirect($baseUrl . '/?slack=connected');

        } catch (\Throwable $e) {
            Log::error('Slack OAuth callback error', ['exception' => $e->getMessage()]);
            return redirect($baseUrl . '/?slack=error&reason=exchange_failed');
        }
    }

    /** Disconnect Slack — revoke token and clear DB. */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->slack_access_token) {
            try {
                Http::asForm()->post('https://slack.com/api/auth.revoke', [
                    'token' => $user->slack_access_token,
                ]);
            } catch (\Throwable $e) {
                // Best-effort revocation — don't fail if Slack is unreachable
                Log::warning('Slack token revocation failed', ['error' => $e->getMessage()]);
            }
        }

        $user->disconnectSlack();

        return response()->json(['ok' => true]);
    }

    /** Check Slack connection status. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasSlackConnection()) {
            return response()->json(['connected' => false]);
        }

        try {
            $slack  = SlackUserService::forUser($user);
            $result = $slack->testAuth();

            return response()->json([
                'connected'     => true,
                'slack_user_id' => $result['user_id'] ?? $user->slack_user_id,
                'team_name'     => $result['team'] ?? $user->slack_team_name,
                'scopes'        => $user->getSlackScopes(),
                'connected_at'  => $user->slack_connected_at?->toIso8601String(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'connected' => false,
                'reason'    => 'token_invalid',
            ]);
        }
    }

    // ─── Channels ────────────────────────────────────────────────

    public function channels(Request $request): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->listChannels(
            $request->integer('limit', 200),
            $request->query('cursor')
        ));
    }

    public function channelHistory(Request $request, string $channelId): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getChannelHistory(
            $channelId,
            $request->integer('limit', 50),
            $request->query('cursor')
        ));
    }

    public function channelInfo(Request $request, string $channelId): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getChannelInfo($channelId));
    }

    public function threadReplies(Request $request, string $channelId, string $ts): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getThreadReplies(
            $channelId,
            $ts,
            $request->integer('limit', 50),
            $request->query('cursor')
        ));
    }

    // ─── Messages ────────────────────────────────────────────────

    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'text'       => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->sendMessage(
            $request->input('channel_id'),
            $request->input('text'),
            $request->only(['thread_ts', 'unfurl_links', 'unfurl_media'])
        ));
    }

    public function updateMessage(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'ts'         => 'required|string',
            'text'       => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->updateMessage(
            $request->input('channel_id'),
            $request->input('ts'),
            $request->input('text')
        ));
    }

    public function deleteMessage(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'ts'         => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->deleteMessage(
            $request->input('channel_id'),
            $request->input('ts')
        ));
    }

    // ─── DMs & Group DMs ─────────────────────────────────────────

    public function directMessages(Request $request): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->listDMs());
    }

    public function dmHistory(Request $request, string $channelId): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getDMHistory(
            $channelId,
            $request->integer('limit', 50),
            $request->query('cursor')
        ));
    }

    public function groupDMs(Request $request): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->listGroupDMs());
    }

    public function openDM(Request $request): JsonResponse
    {
        $request->validate(['user_id' => 'required|string']);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->openDM(
            $request->input('user_id')
        ));
    }

    // ─── Search ──────────────────────────────────────────────────

    public function searchMessages(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string']);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->searchMessages(
            $request->input('q'),
            $request->integer('count', 20),
            $request->integer('page', 1)
        ));
    }

    public function searchFiles(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string']);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->searchFiles(
            $request->input('q'),
            $request->integer('count', 20),
            $request->integer('page', 1)
        ));
    }

    // ─── Users ───────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->listUsers(
            $request->integer('limit', 200),
            $request->query('cursor')
        ));
    }

    public function userInfo(Request $request, string $userId): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getUserInfo($userId));
    }

    public function userPresence(Request $request, string $userId): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->getUserPresence($userId));
    }

    // ─── Files ───────────────────────────────────────────────────

    public function files(Request $request): JsonResponse
    {
        return $this->slackCall($request, fn (SlackUserService $s) => $s->listFiles(
            $request->only(['channel', 'types', 'count', 'page'])
        ));
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'content'    => 'required|string',
            'filename'   => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->uploadFile(
            $request->input('channel_id'),
            $request->input('content'),
            $request->input('filename'),
            $request->input('title')
        ));
    }

    // ─── Reactions ───────────────────────────────────────────────

    public function addReaction(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'timestamp'  => 'required|string',
            'name'       => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->addReaction(
            $request->input('channel_id'),
            $request->input('timestamp'),
            $request->input('name')
        ));
    }

    public function removeReaction(Request $request): JsonResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
            'timestamp'  => 'required|string',
            'name'       => 'required|string',
        ]);

        return $this->slackCall($request, fn (SlackUserService $s) => $s->removeReaction(
            $request->input('channel_id'),
            $request->input('timestamp'),
            $request->input('name')
        ));
    }

    // ─── Huddle AI Notes ─────────────────────────────────────────

    public function huddleNotes(Request $request): JsonResponse
    {
        return $this->slackCall($request, function (SlackUserService $s) {
            $service = new \App\Services\SlackHuddleSyncService();
            return $service->fetchHuddleNotes($s);
        });
    }

    public function syncAllHuddleNotes(Request $request): JsonResponse
    {
        $service = new \App\Services\SlackHuddleSyncService();
        $result  = $service->syncAll($request->user());

        return response()->json(['ok' => true, 'data' => $result]);
    }

    public function syncOneHuddleNote(Request $request): JsonResponse
    {
        $request->validate(['note' => 'required|array']);

        $service = new \App\Services\SlackHuddleSyncService();
        $result  = $service->syncSingleNote($request->input('note'));

        return response()->json(['ok' => true, 'data' => $result]);
    }

    // ─── Shared Helper ───────────────────────────────────────────

    private function slackCall(Request $request, callable $action): JsonResponse
    {
        try {
            $slack  = SlackUserService::forUser($request->user());
            $result = $action($slack);

            return response()->json(['ok' => true, 'data' => $result]);

        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'token invalid') || str_contains($message, 'reconnect')) {
                return response()->json(['error' => $message, 'reconnect' => true], 401);
            }

            if (str_contains($message, 'rate limited')) {
                return response()->json(['error' => $message], 429);
            }

            return response()->json(['error' => $message], 422);
        }
    }
}
