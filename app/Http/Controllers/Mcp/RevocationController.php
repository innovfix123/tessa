<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevocationController extends Controller
{
    // RFC 7009 OAuth 2.0 Token Revocation.
    //
    // The token endpoint to revoke an access or refresh token. Always
    // returns 200 (per spec) so clients can't enumerate which tokens exist.
    public function revoke(Request $request): JsonResponse
    {
        $plain = (string) $request->input('token', '');
        $hint = (string) $request->input('token_type_hint', '');

        if ($plain === '') {
            return response()->json(['ok' => true]);
        }

        $hash = hash('sha256', $plain);

        if ($hint === 'refresh_token' || $hint === '') {
            $refresh = OauthRefreshToken::where('token_hash', $hash)->first();
            if ($refresh) {
                $refresh->forceFill(['revoked_at' => now()])->save();
                if ($refresh->accessToken) {
                    $refresh->accessToken->forceFill(['revoked_at' => now()])->save();
                }
                return response()->json(['ok' => true]);
            }
        }

        if ($hint === 'access_token' || $hint === '') {
            $access = OauthAccessToken::where('token_hash', $hash)->first();
            if ($access) {
                $access->forceFill(['revoked_at' => now()])->save();
                // Also kill any refresh tokens that paired with this access token.
                OauthRefreshToken::where('access_token_id', $access->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }
        }

        return response()->json(['ok' => true]);
    }

    // User-facing revocation from /settings/connect-claude. Authenticated
    // via the existing web session — users can only revoke their own
    // tokens. Used for the "Disconnect" buttons.
    public function userRevoke(Request $request, OauthAccessToken $accessToken): JsonResponse
    {
        $user = $request->user();
        if (! $user || $accessToken->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $accessToken->forceFill(['revoked_at' => now()])->save();
        OauthRefreshToken::where('access_token_id', $accessToken->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        return response()->json(['ok' => true]);
    }

    // Revoke an entire OAuth client (kills every token issued under it
    // for the current user). Useful for "Remove this app" UX.
    public function userRevokeClient(Request $request, OauthClient $oauthClient): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        OauthAccessToken::where('client_internal_id', $oauthClient->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        OauthRefreshToken::where('client_internal_id', $oauthClient->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
