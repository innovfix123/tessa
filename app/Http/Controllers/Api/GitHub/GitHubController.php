<?php

namespace App\Http\Controllers\Api\GitHub;

use App\Http\Controllers\Controller;
use App\Models\TessaTask;
use App\Services\GitHubUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GitHubController extends Controller
{
    // ─── OAuth Flow ──────────────────────────────────────────────

    public function connect(Request $request): JsonResponse
    {
        $config = config('services.github.oauth');

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return response()->json(['ok' => false, 'error' => 'GitHub integration is not configured yet.'], 422);
        }

        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        $url = 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'scope'        => $config['scopes'],
            'state'        => $state,
        ]);

        return response()->json(['ok' => true, 'url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $baseUrl = config('app.url');

        $expectedState = $request->session()->pull('github_oauth_state');
        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect($baseUrl . '/?github=error&reason=invalid_state');
        }

        if ($request->query('error')) {
            return redirect($baseUrl . '/?github=error&reason=access_denied');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect($baseUrl . '/?github=error&reason=missing_code');
        }

        try {
            $config = config('services.github.oauth');

            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->post('https://github.com/login/oauth/access_token', [
                    'client_id'     => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'code'          => $code,
                    'redirect_uri'  => $config['redirect_uri'],
                ]);

            $data = $response->json();

            if (! ($data['access_token'] ?? null)) {
                Log::error('GitHub OAuth exchange failed', ['error' => $data['error'] ?? 'no token']);
                return redirect($baseUrl . '/?github=error&reason=exchange_failed');
            }

            $token = $data['access_token'];
            $scopes = $data['scope'] ?? '';

            // Get GitHub user info
            $userInfo = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/user')
                ->json();

            $request->user()->update([
                'github_user_id'      => (string) ($userInfo['id'] ?? ''),
                'github_username'     => $userInfo['login'] ?? null,
                'github_access_token' => $token,
                'github_avatar_url'   => $userInfo['avatar_url'] ?? null,
                'github_scopes'       => $scopes,
                'github_connected_at' => now(),
            ]);

            return redirect($baseUrl . '/?github=connected');

        } catch (\Throwable $e) {
            Log::error('GitHub OAuth callback error', ['exception' => $e->getMessage()]);
            return redirect($baseUrl . '/?github=error&reason=exchange_failed');
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->user()->disconnectGitHub();

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasGitHubConnection()) {
            return response()->json(['connected' => false]);
        }

        try {
            $gh     = GitHubUserService::forUser($user);
            $result = $gh->getAuthUser();

            return response()->json([
                'connected'  => true,
                'username'   => $result['login'] ?? $user->github_username,
                'avatar_url' => $result['avatar_url'] ?? $user->github_avatar_url,
                'name'       => $result['name'] ?? null,
                'scopes'     => $user->github_scopes,
                'connected_at' => $user->github_connected_at?->toIso8601String(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['connected' => false, 'reason' => 'token_invalid']);
        }
    }

    // ─── Repos ───────────────────────────────────────────────────

    public function repos(Request $request): JsonResponse
    {
        return $this->ghCall($request, fn (GitHubUserService $gh) => $gh->listRepos(
            $request->integer('per_page', 30),
            $request->integer('page', 1)
        ));
    }

    // ─── Branches ────────────────────────────────────────────────

    public function branches(Request $request, string $owner, string $repo): JsonResponse
    {
        return $this->ghCall($request, fn (GitHubUserService $gh) => $gh->listBranches($owner, $repo));
    }

    // ─── Pull Requests ───────────────────────────────────────────

    public function pullRequests(Request $request, string $owner, string $repo): JsonResponse
    {
        return $this->ghCall($request, fn (GitHubUserService $gh) => $gh->listPullRequests(
            $owner, $repo,
            $request->query('state', 'open')
        ));
    }

    // ─── Commits ─────────────────────────────────────────────────

    public function commits(Request $request, string $owner, string $repo): JsonResponse
    {
        return $this->ghCall($request, fn (GitHubUserService $gh) => $gh->listCommits(
            $owner, $repo,
            $request->integer('per_page', 20),
            $request->query('sha'),
            $request->query('since')
        ));
    }

    // ─── Activity ────────────────────────────────────────────────

    public function activity(Request $request): JsonResponse
    {
        return $this->ghCall($request, fn (GitHubUserService $gh) => $gh->getUserEvents(30));
    }

    // ─── Task ↔ GitHub ───────────────────────────────────────────

    public function createBranchForTask(Request $request, int $taskId): JsonResponse
    {
        $request->validate([
            'owner' => 'required|string',
            'repo'  => 'required|string',
        ]);

        $task = TessaTask::findOrFail($taskId);

        if ($task->github_branch) {
            return response()->json(['error' => 'Branch already exists for this task: ' . $task->github_branch], 422);
        }

        $owner = $request->input('owner');
        $repo  = $request->input('repo');

        try {
            $gh = GitHubUserService::forUser($request->user());

            // Get default branch SHA
            $sha = $gh->getDefaultBranchSha($owner, $repo);
            if (! $sha) {
                return response()->json(['error' => 'Could not get default branch'], 422);
            }

            // Create branch name from task
            $slug = Str::slug(Str::limit($task->title, 40, ''));
            $branchName = "feature/TSK-{$task->id}-{$slug}";

            $result = $gh->createBranch($owner, $repo, $branchName, $sha);

            $task->update([
                'github_branch' => $branchName,
                'github_repo'   => "{$owner}/{$repo}",
            ]);

            return response()->json([
                'ok'     => true,
                'branch' => $branchName,
                'repo'   => "{$owner}/{$repo}",
                'ref'    => $result['ref'] ?? null,
            ]);

        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function taskStatus(Request $request, int $taskId): JsonResponse
    {
        $task = TessaTask::findOrFail($taskId);

        if (! $task->github_branch || ! $task->github_repo) {
            return response()->json(['ok' => true, 'linked' => false]);
        }

        [$owner, $repo] = explode('/', $task->github_repo, 2);

        try {
            $gh = GitHubUserService::forUser($request->user());

            // Get branch info
            $branch = null;
            try {
                $branch = $gh->getBranch($owner, $repo, $task->github_branch);
            } catch (\Throwable $e) {
                // Branch may have been deleted
            }

            // Check for PRs from this branch
            $prs = $gh->listPullRequests($owner, $repo, 'all', 10);
            $taskPr = null;
            foreach ($prs as $pr) {
                if (($pr['head']['ref'] ?? '') === $task->github_branch) {
                    $taskPr = [
                        'number' => $pr['number'],
                        'title'  => $pr['title'],
                        'state'  => $pr['state'],
                        'merged' => $pr['merged_at'] !== null,
                        'url'    => $pr['html_url'],
                    ];

                    // Update task PR info
                    $task->update([
                        'github_pr_url'    => $pr['html_url'],
                        'github_pr_status' => $pr['merged_at'] ? 'merged' : $pr['state'],
                    ]);
                    break;
                }
            }

            // Get recent commits on branch
            $commits = [];
            try {
                $commits = $gh->listCommits($owner, $repo, 5, $task->github_branch);
            } catch (\Throwable $e) {
                // Branch may not exist
            }

            $recentCommits = array_map(fn ($c) => [
                'sha'     => substr($c['sha'] ?? '', 0, 7),
                'message' => $c['commit']['message'] ?? '',
                'author'  => $c['commit']['author']['name'] ?? '',
                'date'    => $c['commit']['author']['date'] ?? '',
            ], array_slice($commits, 0, 5));

            return response()->json([
                'ok'      => true,
                'linked'  => true,
                'branch'  => $task->github_branch,
                'repo'    => $task->github_repo,
                'pr'      => $taskPr,
                'commits' => $recentCommits,
                'branch_exists' => $branch !== null,
            ]);

        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─── Shared Helper ───────────────────────────────────────────

    private function ghCall(Request $request, callable $action): JsonResponse
    {
        try {
            $gh     = GitHubUserService::forUser($request->user());
            $result = $action($gh);

            return response()->json(['ok' => true, 'data' => $result]);

        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'token invalid') || str_contains($message, 'reconnect')) {
                return response()->json(['error' => $message, 'reconnect' => true], 401);
            }

            if (str_contains($message, 'rate limit')) {
                return response()->json(['error' => $message], 429);
            }

            return response()->json(['error' => $message], 422);
        }
    }
}
