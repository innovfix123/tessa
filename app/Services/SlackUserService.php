<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SlackUserService
{
    private string $token;
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;

        if (! $user->slack_access_token) {
            throw new RuntimeException('Slack not connected. Please connect your Slack account first.');
        }

        $this->token = $user->slack_access_token;
    }

    public static function forUser(User $user): self
    {
        return new self($user);
    }

    public function getToken(): string
    {
        return $this->token;
    }

    // ─── Core API Call ───────────────────────────────────────────

    private function apiCall(string $method, string $endpoint, array $params = []): array
    {
        $url = "https://slack.com/api/{$endpoint}";

        $response = match ($method) {
            'get'  => Http::withToken($this->token)->get($url, $params),
            'post' => Http::withToken($this->token)->asForm()->post($url, $params),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };

        if (! $response->successful()) {
            throw new RuntimeException("Slack API HTTP error: {$response->status()}");
        }

        $data = $response->json();

        if (! ($data['ok'] ?? false)) {
            $error = $data['error'] ?? 'unknown_error';

            if (in_array($error, ['token_revoked', 'token_expired', 'invalid_auth', 'not_authed'])) {
                $this->user->disconnectSlack();
                throw new RuntimeException("Slack token invalid: {$error}. Please reconnect your Slack account.");
            }

            if ($error === 'ratelimited') {
                $retryAfter = $response->header('Retry-After', '30');
                throw new RuntimeException("Slack rate limited. Retry after {$retryAfter} seconds.");
            }

            throw new RuntimeException("Slack API error: {$error}");
        }

        return $data;
    }

    // ─── Auth ────────────────────────────────────────────────────

    public function testAuth(): array
    {
        return $this->apiCall('post', 'auth.test');
    }

    // ─── Channels ────────────────────────────────────────────────

    public function listChannels(int $limit = 200, ?string $cursor = null): array
    {
        $params = [
            'types'           => 'public_channel,private_channel',
            'exclude_archived' => true,
            'limit'           => $limit,
        ];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->apiCall('get', 'conversations.list', $params);
    }

    public function getChannelInfo(string $channelId): array
    {
        return $this->apiCall('get', 'conversations.info', ['channel' => $channelId]);
    }

    public function getChannelHistory(string $channelId, int $limit = 50, ?string $cursor = null): array
    {
        $params = ['channel' => $channelId, 'limit' => $limit];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->apiCall('get', 'conversations.history', $params);
    }

    /**
     * Fetch messages in a channel within a time window [oldest, latest], used to
     * pull the replies that follow one of the user's own messages. Returns
     * Slack's raw payload (messages are newest-first).
     */
    public function getHistoryWindow(string $channelId, string $oldest, ?string $latest = null, int $limit = 10): array
    {
        $params = [
            'channel' => $channelId,
            'oldest' => $oldest,
            'inclusive' => true,
            'limit' => $limit,
        ];
        if ($latest) {
            $params['latest'] = $latest;
        }

        return $this->apiCall('get', 'conversations.history', $params);
    }

    public function getThreadReplies(string $channelId, string $ts, int $limit = 50, ?string $cursor = null): array
    {
        $params = ['channel' => $channelId, 'ts' => $ts, 'limit' => $limit];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->apiCall('get', 'conversations.replies', $params);
    }

    public function createChannel(string $name, bool $isPrivate = false): array
    {
        return $this->apiCall('post', 'conversations.create', [
            'name'       => $name,
            'is_private' => $isPrivate,
        ]);
    }

    public function joinChannel(string $channelId): array
    {
        return $this->apiCall('post', 'conversations.join', ['channel' => $channelId]);
    }

    public function archiveChannel(string $channelId): array
    {
        return $this->apiCall('post', 'conversations.archive', ['channel' => $channelId]);
    }

    // ─── Messages ────────────────────────────────────────────────

    public function sendMessage(string $channelId, string $text, array $options = []): array
    {
        return $this->apiCall('post', 'chat.postMessage', array_merge([
            'channel' => $channelId,
            'text'    => $text,
        ], $options));
    }

    public function updateMessage(string $channelId, string $ts, string $text): array
    {
        return $this->apiCall('post', 'chat.update', [
            'channel' => $channelId,
            'ts'      => $ts,
            'text'    => $text,
        ]);
    }

    public function deleteMessage(string $channelId, string $ts): array
    {
        return $this->apiCall('post', 'chat.delete', [
            'channel' => $channelId,
            'ts'      => $ts,
        ]);
    }

    // ─── DMs & Group DMs ─────────────────────────────────────────

    public function listDMs(): array
    {
        return $this->apiCall('get', 'conversations.list', [
            'types' => 'im',
            'limit' => 200,
        ]);
    }

    public function getDMHistory(string $channelId, int $limit = 50, ?string $cursor = null): array
    {
        $params = ['channel' => $channelId, 'limit' => $limit];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->apiCall('get', 'conversations.history', $params);
    }

    public function listGroupDMs(): array
    {
        return $this->apiCall('get', 'conversations.list', [
            'types' => 'mpim',
            'limit' => 200,
        ]);
    }

    public function openDM(string $userId): array
    {
        return $this->apiCall('post', 'conversations.open', ['users' => $userId]);
    }

    // ─── Search ──────────────────────────────────────────────────

    public function searchMessages(string $query, int $count = 20, int $page = 1, ?string $sort = null, ?string $sortDir = null): array
    {
        $params = [
            'query' => $query,
            'count' => $count,
            'page'  => $page,
        ];
        if ($sort) {
            $params['sort'] = $sort;          // 'score' | 'timestamp'
        }
        if ($sortDir) {
            $params['sort_dir'] = $sortDir;   // 'asc' | 'desc'
        }

        return $this->apiCall('get', 'search.messages', $params);
    }

    public function searchFiles(string $query, int $count = 20, int $page = 1): array
    {
        return $this->apiCall('get', 'search.files', [
            'query' => $query,
            'count' => $count,
            'page'  => $page,
        ]);
    }

    // ─── Users ───────────────────────────────────────────────────

    public function listUsers(int $limit = 200, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->apiCall('get', 'users.list', $params);
    }

    public function getUserInfo(string $userId): array
    {
        return $this->apiCall('get', 'users.info', ['user' => $userId]);
    }

    public function getUserPresence(string $userId): array
    {
        return $this->apiCall('get', 'users.getPresence', ['user' => $userId]);
    }

    // ─── Files ───────────────────────────────────────────────────

    public function listFiles(array $filters = []): array
    {
        return $this->apiCall('get', 'files.list', $filters);
    }

    public function uploadFile(string $channelId, string $content, string $filename, ?string $title = null): array
    {
        $params = [
            'channels'        => $channelId,
            'content'         => $content,
            'filename'        => $filename,
            'title'           => $title ?? $filename,
        ];

        return $this->apiCall('post', 'files.upload', $params);
    }

    public function getFileInfo(string $fileId): array
    {
        return $this->apiCall('get', 'files.info', ['file' => $fileId]);
    }

    public function deleteFile(string $fileId): array
    {
        return $this->apiCall('post', 'files.delete', ['file' => $fileId]);
    }

    // ─── Reactions ───────────────────────────────────────────────

    public function addReaction(string $channelId, string $timestamp, string $name): array
    {
        return $this->apiCall('post', 'reactions.add', [
            'channel'   => $channelId,
            'timestamp' => $timestamp,
            'name'      => $name,
        ]);
    }

    public function removeReaction(string $channelId, string $timestamp, string $name): array
    {
        return $this->apiCall('post', 'reactions.remove', [
            'channel'   => $channelId,
            'timestamp' => $timestamp,
            'name'      => $name,
        ]);
    }

    public function getReactions(string $channelId, string $timestamp): array
    {
        return $this->apiCall('get', 'reactions.get', [
            'channel'   => $channelId,
            'timestamp' => $timestamp,
        ]);
    }
}
