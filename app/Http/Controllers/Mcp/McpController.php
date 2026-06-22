<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\McpToolRegistry;
use App\Mcp\ToolException;
use App\Models\McpCallLog;
use App\Models\OauthAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class McpController extends Controller
{
    // JSON-RPC 2.0 error codes per spec (used by the MCP transport).
    private const ERR_PARSE = -32700;
    private const ERR_INVALID_REQUEST = -32600;
    private const ERR_METHOD_NOT_FOUND = -32601;
    private const ERR_INVALID_PARAMS = -32602;
    private const ERR_INTERNAL = -32603;

    public function __construct(private readonly McpToolRegistry $registry) {}

    // Streamable HTTP endpoint (POST = JSON-RPC, DELETE = session tear-down).
    //
    // Bearer auth is enforced by the 'mcp.access-token' route middleware
    // BEFORE we get here — so Auth::user() and the mcp_access_token
    // request attribute are both populated.
    public function handle(Request $request): Response
    {
        if ($request->isMethod('delete')) {
            // Stateless server — no per-session state to drop.
            return response()->noContent(204);
        }

        // The /mcp endpoint serves two kinds of caller:
        //   - Browser-based callers (Claude.ai) MUST send a valid Origin.
        //   - Server-to-server callers (Anthropic Messages API MCP feature)
        //     send no Origin. They authenticate solely with the Bearer token.
        if ($request->headers->has('Origin')) {
            $origin = (string) $request->header('Origin');
            $allowed = (array) config('mcp.allowed_origins', []);
            if (! in_array($origin, $allowed, true)) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => self::ERR_INVALID_REQUEST, 'message' => 'Origin not allowed.'],
                    'id' => null,
                ], 403);
            }
        }

        $accessToken = $request->attributes->get('mcp_access_token');
        if (! ($accessToken instanceof OauthAccessToken)) {
            return $this->jsonRpcError(null, self::ERR_INTERNAL, 'Auth token attribute missing.');
        }

        return $this->dispatchJsonRpc($request, $accessToken);
    }

    // GET /mcp — the spec allows the server to return 405 if it doesn't
    // serve an SSE stream. We're stateless, so 405 is honest. Claude.ai
    // gracefully falls back to POST-only.
    public function rejectGet(): Response
    {
        return response('Method Not Allowed', 405)
            ->header('Allow', 'POST, DELETE');
    }

    private function dispatchJsonRpc(Request $request, OauthAccessToken $accessToken): JsonResponse
    {
        $started = microtime(true);
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return $this->jsonRpcError(null, self::ERR_PARSE, 'Request body must be JSON.');
        }
        // Single requests vs batched arrays per JSON-RPC 2.0.
        $isBatch = array_is_list($payload) && ! empty($payload);
        $messages = $isBatch ? $payload : [$payload];

        $responses = [];
        foreach ($messages as $message) {
            if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
                $responses[] = $this->errorBody(null, self::ERR_INVALID_REQUEST, 'Invalid JSON-RPC envelope.');
                continue;
            }
            $id = $message['id'] ?? null;
            $method = (string) ($message['method'] ?? '');
            $params = (array) ($message['params'] ?? []);

            $isNotification = ! array_key_exists('id', $message);

            try {
                $result = $this->dispatchMethod($method, $params, Auth::user(), $request, $accessToken, $started);
                if (! $isNotification) {
                    $responses[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
                }
            } catch (ToolException $e) {
                $this->logCall($request, $accessToken, $method, $params, $e->statusCode, (int) round((microtime(true) - $started) * 1000), $e->getMessage());
                if (! $isNotification) {
                    $responses[] = $this->errorBody($id, $this->jsonRpcCodeFor($e->statusCode), $e->getMessage(), $e->data);
                }
            } catch (Throwable $e) {
                Log::error('MCP tool dispatch failed', ['error' => $e->getMessage(), 'method' => $method]);
                $this->logCall($request, $accessToken, $method, $params, 500, (int) round((microtime(true) - $started) * 1000), $e->getMessage());
                if (! $isNotification) {
                    $responses[] = $this->errorBody($id, self::ERR_INTERNAL, 'Internal server error.');
                }
            }
        }

        if (empty($responses)) {
            // Notification-only batch — JSON-RPC says return 204.
            return response()->json(null, 204);
        }
        return response()->json($isBatch ? $responses : $responses[0]);
    }

    private function dispatchMethod(string $method, array $params, $user, Request $request, OauthAccessToken $accessToken, float $started): mixed
    {
        return match ($method) {
            'initialize' => $this->handleInitialize($params),
            'notifications/initialized' => null,
            'ping' => new \stdClass(),
            'tools/list' => $this->handleToolsList($user, $request, $accessToken, $started),
            'tools/call' => $this->handleToolsCall($params, $user, $request, $accessToken, $started),
            default => throw new ToolException("Method '{$method}' is not supported.", 404),
        };
    }

    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'logging' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => 'tessa-mcp',
                'version' => '1.0.0',
                'title' => 'Tessa',
            ],
            'instructions' => 'Tessa is the InnovFix internal portal — tasks, meetings, KRAs, HR, agile. Always call whoami first to see who you are signed in as. The available tools are filtered to what your Tessa role can do; if a tool you expect is missing, your role does not permit it.',
        ];
    }

    private function handleToolsList($user, Request $request, OauthAccessToken $accessToken, float $started): array
    {
        if (! $user) {
            throw new ToolException('Not authenticated.', 401);
        }
        $tools = $this->registry->toolsForUser($user);
        $this->logCall($request, $accessToken, 'tools/list', [], 200, (int) round((microtime(true) - $started) * 1000));
        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params, $user, Request $request, OauthAccessToken $accessToken, float $started): array
    {
        if (! $user) {
            throw new ToolException('Not authenticated.', 401);
        }
        $name = (string) ($params['name'] ?? '');
        $args = (array) ($params['arguments'] ?? []);
        if ($name === '') {
            throw new ToolException('Tool name is required.', 400);
        }
        try {
            $result = $this->registry->call($name, $args, $user, $request);
            $this->logCall($request, $accessToken, 'tools/call', ['name' => $name, 'args' => $args], 200, (int) round((microtime(true) - $started) * 1000));
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
                'isError' => false,
                'structuredContent' => is_array($result) ? $result : null,
            ];
        } catch (ToolException $e) {
            $this->logCall($request, $accessToken, 'tools/call', ['name' => $name], $e->statusCode, (int) round((microtime(true) - $started) * 1000), $e->getMessage());
            // tools/call errors come back as a result with isError=true,
            // NOT as a JSON-RPC error — that's how the MCP spec works so
            // the model can see the failure and recover.
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $e->getMessage(),
                ]],
                'isError' => true,
            ];
        }
    }

    private function logCall(
        Request $request,
        OauthAccessToken $token,
        string $method,
        array $params,
        int $statusCode,
        int $durationMs,
        ?string $error = null,
    ): void {
        try {
            $toolName = $method === 'tools/call'
                ? (string) ($params['name'] ?? '')
                : null;
            $argsForFingerprint = $method === 'tools/call'
                ? (array) ($params['args'] ?? $params['arguments'] ?? [])
                : $params;
            McpCallLog::create([
                'user_id' => $token->user_id,
                'client_internal_id' => $token->client_internal_id,
                'access_token_id' => $token->id,
                'jsonrpc_method' => $method,
                'tool_name' => $toolName !== '' ? $toolName : null,
                'args_fingerprint' => $argsForFingerprint
                    ? substr(hash('sha256', json_encode($argsForFingerprint)), 0, 16)
                    : null,
                'status_code' => $statusCode,
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'error_message' => $error ? substr($error, 0, 1000) : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('mcp_call_log insert failed', ['error' => $e->getMessage()]);
        }
    }

    private function jsonRpcCodeFor(int $http): int
    {
        return match (true) {
            $http === 400 => self::ERR_INVALID_PARAMS,
            $http === 401 => self::ERR_INVALID_REQUEST,
            $http === 403 => self::ERR_INVALID_REQUEST,
            $http === 404 => self::ERR_METHOD_NOT_FOUND,
            default => self::ERR_INTERNAL,
        };
    }

    private function errorBody(mixed $id, int $code, string $message, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function jsonRpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json($this->errorBody($id, $code, $message), 200);
    }
}
