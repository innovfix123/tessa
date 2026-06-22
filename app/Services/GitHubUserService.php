<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubUserService
{
    private const API_BASE = 'https://api.github.com';

    private string $token;
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;

        if (! $user->github_access_token) {
            throw new RuntimeException('GitHub not connected. Please connect your GitHub account first.');
        }

        $this->token = $user->github_access_token;
    }

    public static function forUser(User $user): self
    {
        return new self($user);
    }

    // ─── Core API Call ───────────────────────────────────────────

    private function apiCall(string $method, string $endpoint, array $params = []): array
    {
        $url = self::API_BASE . '/' . ltrim($endpoint, '/');

        $request = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);

        $response = match ($method) {
            'get'    => $request->get($url, $params),
            'post'   => $request->post($url, $params),
            'put'    => $request->put($url, $params),
            'patch'  => $request->patch($url, $params),
            'delete' => $request->delete($url, $params),
            default  => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };

        if ($response->status() === 401) {
            $this->user->disconnectGitHub();
            throw new RuntimeException('GitHub token invalid. Please reconnect your GitHub account.');
        }

        if ($response->status() === 403 && str_contains($response->body(), 'rate limit')) {
            $reset = $response->header('X-RateLimit-Reset');
            throw new RuntimeException("GitHub rate limited. Resets at " . ($reset ? date('H:i', (int) $reset) : 'soon'));
        }

        if (! $response->successful()) {
            $error = $response->json('message') ?? "HTTP {$response->status()}";
            throw new RuntimeException("GitHub API error: {$error}");
        }

        return $response->json() ?? [];
    }

    // ─── Auth ────────────────────────────────────────────────────

    public function getAuthUser(): array
    {
        return $this->apiCall('get', 'user');
    }

    // ─── Repos ───────────────────────────────────────────────────

    public function listRepos(int $perPage = 30, int $page = 1, string $sort = 'pushed'): array
    {
        return $this->apiCall('get', 'user/repos', [
            'per_page'  => $perPage,
            'page'      => $page,
            'sort'      => $sort,
            'direction' => 'desc',
            'type'      => 'all',
        ]);
    }

    public function getRepo(string $owner, string $repo): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}");
    }

    // ─── Branches ────────────────────────────────────────────────

    public function listBranches(string $owner, string $repo, int $perPage = 30): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/branches", ['per_page' => $perPage]);
    }

    public function getBranch(string $owner, string $repo, string $branch): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/branches/{$branch}");
    }

    public function createBranch(string $owner, string $repo, string $branchName, string $fromSha): array
    {
        return $this->apiCall('post', "repos/{$owner}/{$repo}/git/refs", [
            'ref' => "refs/heads/{$branchName}",
            'sha' => $fromSha,
        ]);
    }

    public function getDefaultBranchSha(string $owner, string $repo): string
    {
        $repoInfo = $this->getRepo($owner, $repo);
        $defaultBranch = $repoInfo['default_branch'] ?? 'main';
        $branchInfo = $this->getBranch($owner, $repo, $defaultBranch);

        return $branchInfo['commit']['sha'] ?? '';
    }

    // ─── Pull Requests ───────────────────────────────────────────

    public function listPullRequests(string $owner, string $repo, string $state = 'open', int $perPage = 20): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/pulls", [
            'state'    => $state,
            'per_page' => $perPage,
            'sort'     => 'updated',
            'direction' => 'desc',
        ]);
    }

    public function getPullRequest(string $owner, string $repo, int $number): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/pulls/{$number}");
    }

    public function createPullRequest(string $owner, string $repo, string $title, string $head, string $base = 'main', string $body = ''): array
    {
        return $this->apiCall('post', "repos/{$owner}/{$repo}/pulls", [
            'title' => $title,
            'head'  => $head,
            'base'  => $base,
            'body'  => $body,
        ]);
    }

    // ─── Commits ─────────────────────────────────────────────────

    public function listCommits(string $owner, string $repo, int $perPage = 20, ?string $sha = null, ?string $since = null): array
    {
        $params = ['per_page' => $perPage];
        if ($sha) $params['sha'] = $sha;
        if ($since) $params['since'] = $since;

        return $this->apiCall('get', "repos/{$owner}/{$repo}/commits", $params);
    }

    public function getCommit(string $owner, string $repo, string $sha): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/commits/{$sha}");
    }

    // ─── Activity (Events) ───────────────────────────────────────

    public function getUserEvents(int $perPage = 30): array
    {
        $username = $this->user->github_username;
        if (! $username) return [];

        return $this->apiCall('get', "users/{$username}/events", ['per_page' => $perPage]);
    }

    public function getRepoEvents(string $owner, string $repo, int $perPage = 30): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/events", ['per_page' => $perPage]);
    }

    // ─── Issues ──────────────────────────────────────────────────

    public function listIssues(string $owner, string $repo, string $state = 'open', int $perPage = 20): array
    {
        return $this->apiCall('get', "repos/{$owner}/{$repo}/issues", [
            'state'    => $state,
            'per_page' => $perPage,
            'sort'     => 'updated',
        ]);
    }

    public function createIssue(string $owner, string $repo, string $title, string $body = ''): array
    {
        return $this->apiCall('post', "repos/{$owner}/{$repo}/issues", [
            'title' => $title,
            'body'  => $body,
        ]);
    }
}
