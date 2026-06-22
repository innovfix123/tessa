<?php

namespace App\Http\Controllers\Api\Google;

use App\Http\Controllers\Controller;
use App\Services\GoogleUserService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleController extends Controller
{
    // ─── OAuth Flow ──────────────────────────────────────────────

    public function connect(Request $request): JsonResponse
    {
        $config = config('services.google.oauth');

        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return response()->json(['ok' => false, 'error' => 'Google integration is not configured yet.'], 422);
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $scopes = $config['scopes'] ?? '';

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $config['client_id'],
            'redirect_uri'  => $config['redirect_uri'],
            'response_type' => 'code',
            'scope'         => $scopes,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        return response()->json(['ok' => true, 'url' => $url]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $baseUrl = config('app.url');

        $expectedState = $request->session()->pull('google_oauth_state');
        if (! $expectedState || $request->query('state') !== $expectedState) {
            return redirect($baseUrl . '/?google=error&reason=invalid_state');
        }

        if ($request->query('error')) {
            return redirect($baseUrl . '/?google=error&reason=access_denied');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect($baseUrl . '/?google=error&reason=missing_code');
        }

        try {
            $config = config('services.google.oauth');

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $config['redirect_uri'],
                'grant_type'    => 'authorization_code',
            ]);

            $data = $response->json();

            if (! ($data['access_token'] ?? null)) {
                Log::error('Google OAuth exchange failed', ['error' => $data['error'] ?? 'no token']);
                return redirect($baseUrl . '/?google=error&reason=exchange_failed');
            }

            // Get user info
            $userInfo = Http::withToken($data['access_token'])
                ->get('https://www.googleapis.com/oauth2/v2/userinfo')
                ->json();

            $request->user()->update([
                'google_user_id'          => (string) ($userInfo['id'] ?? ''),
                'google_email'            => $userInfo['email'] ?? null,
                'google_access_token'     => $data['access_token'],
                'google_refresh_token'    => $data['refresh_token'] ?? null,
                'google_name'             => $userInfo['name'] ?? null,
                'google_avatar_url'       => $userInfo['picture'] ?? null,
                'google_scopes'           => $data['scope'] ?? null,
                'google_connected_at'     => now(),
                'google_token_expires_at' => isset($data['expires_in'])
                    ? now()->addSeconds($data['expires_in'])
                    : null,
            ]);

            return redirect($baseUrl . '/?google=connected');

        } catch (\Throwable $e) {
            Log::error('Google OAuth callback error', ['exception' => $e->getMessage()]);
            return redirect($baseUrl . '/?google=error&reason=exchange_failed');
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->google_access_token) {
            try {
                Http::post('https://oauth2.googleapis.com/revoke', [
                    'token' => $user->google_access_token,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Google token revocation failed', ['error' => $e->getMessage()]);
            }
        }

        $user->disconnectGoogle();

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasGoogleConnection()) {
            return response()->json(['connected' => false]);
        }

        try {
            $google = GoogleUserService::forUser($user);
            $info   = $google->getUserInfo();

            return response()->json([
                'connected'    => true,
                'email'        => $info['email'] ?? $user->google_email,
                'name'         => $info['name'] ?? $user->google_name,
                'avatar_url'   => $info['picture'] ?? $user->google_avatar_url,
                'scopes'       => $user->google_scopes,
                'connected_at' => $user->google_connected_at?->toIso8601String(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['connected' => false, 'reason' => 'token_invalid']);
        }
    }

    // ─── Gmail ───────────────────────────────────────────────────

    public function gmailMessages(Request $request): JsonResponse
    {
        return $this->googleCall($request, function (GoogleUserService $g) use ($request) {
            $list = $g->listMessages(
                $request->integer('max', 20),
                $request->query('q'),
                $request->query('pageToken')
            );

            $messageIds = array_map(fn ($m) => $m['id'], $list['messages'] ?? []);
            $messages   = $g->getMessageSnippets($messageIds);

            return [
                'messages'      => $messages,
                'nextPageToken' => $list['nextPageToken'] ?? null,
                'resultSizeEstimate' => $list['resultSizeEstimate'] ?? 0,
            ];
        });
    }

    public function gmailRead(Request $request, string $messageId): JsonResponse
    {
        return $this->googleCall($request, fn (GoogleUserService $g) => $g->getMessage($messageId));
    }

    // ─── Calendar ────────────────────────────────────────────────

    public function calendarEvents(Request $request): JsonResponse
    {
        return $this->googleCall($request, function (GoogleUserService $g) use ($request) {
            $date = $request->query('date', Carbon::today('Asia/Kolkata')->toDateString());

            return $g->getEventsForDate($date);
        });
    }

    public function calendarCreateEvent(Request $request): JsonResponse
    {
        $request->validate([
            'title'      => 'required|string|max:255',
            'start_time' => 'required|string',
            'end_time'   => 'required|string',
            'attendees'  => 'nullable|array',
        ]);

        return $this->googleCall($request, function (GoogleUserService $g) use ($request) {
            $event = [
                'summary' => $request->input('title'),
                'start'   => ['dateTime' => $request->input('start_time'), 'timeZone' => 'Asia/Kolkata'],
                'end'     => ['dateTime' => $request->input('end_time'), 'timeZone' => 'Asia/Kolkata'],
            ];

            if ($request->has('attendees')) {
                $event['attendees'] = array_map(fn ($email) => ['email' => $email], $request->input('attendees'));
            }

            if ($request->input('description')) {
                $event['description'] = $request->input('description');
            }

            $result = $g->createEvent('primary', $event);

            return [
                'event_id'  => $result['id'] ?? null,
                'html_link' => $result['htmlLink'] ?? null,
                'meet_link' => $result['hangoutLink'] ?? null,
                'status'    => $result['status'] ?? 'confirmed',
            ];
        });
    }

    // ─── Calendar notes (personal Calendar section) ──────────────

    /** All events in a given month (year + month, 1-based), for the month grid. */
    public function calendarMonth(Request $request): JsonResponse
    {
        $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        return $this->googleCall($request, function (GoogleUserService $g) use ($request) {
            $first = Carbon::create($request->integer('year'), $request->integer('month'), 1, 0, 0, 0, 'Asia/Kolkata');

            return $g->getEventsForRange($first->toDateString(), $first->copy()->endOfMonth()->toDateString());
        });
    }

    /** Today → today+N days, for the dashboard "Calendar" notification card. */
    public function calendarUpcoming(Request $request): JsonResponse
    {
        $days = max(1, min(31, $request->integer('days', 7)));

        return $this->googleCall($request, function (GoogleUserService $g) use ($days) {
            $today = Carbon::today('Asia/Kolkata');

            return $g->getEventsForRange($today->toDateString(), $today->copy()->addDays($days - 1)->toDateString());
        });
    }

    /** Add an all-day note on a date (a real all-day Google Calendar event). */
    public function calendarCreateNote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'date'        => 'required|date',
            'description' => 'nullable|string|max:2000',
        ]);

        return $this->googleCall($request, function (GoogleUserService $g) use ($validated) {
            $result = $g->createNote($validated['title'], $validated['date'], $validated['description'] ?? null);

            return [
                'event_id'  => $result['id'] ?? null,
                'html_link' => $result['htmlLink'] ?? null,
                'status'    => $result['status'] ?? 'confirmed',
            ];
        });
    }

    /** Edit a note's title / date / description (only supplied fields change). */
    public function calendarUpdateNote(Request $request, string $eventId): JsonResponse
    {
        $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'date'        => 'sometimes|required|date',
            'description' => 'nullable|string|max:2000',
        ]);

        return $this->googleCall($request, function (GoogleUserService $g) use ($request, $eventId) {
            $fields = [];
            if ($request->has('title'))       $fields['title'] = $request->input('title');
            if ($request->has('date'))        $fields['date'] = $request->input('date');
            if ($request->has('description')) $fields['description'] = $request->input('description');

            $result = $g->updateNote($eventId, $fields);

            return ['event_id' => $result['id'] ?? $eventId, 'status' => $result['status'] ?? 'confirmed'];
        });
    }

    /** Delete a note/event from the user's primary calendar. */
    public function calendarDeleteNote(Request $request, string $eventId): JsonResponse
    {
        return $this->googleCall($request, function (GoogleUserService $g) use ($eventId) {
            $g->deleteEvent('primary', $eventId);

            return ['deleted' => true];
        });
    }

    // ─── Drive ───────────────────────────────────────────────────

    public function driveFiles(Request $request): JsonResponse
    {
        return $this->googleCall($request, function (GoogleUserService $g) use ($request) {
            if ($request->query('q')) {
                return $g->searchFiles($request->query('q'));
            }

            return $g->listFiles(
                $request->integer('pageSize', 20),
                null,
                $request->query('pageToken')
            );
        });
    }

    // ─── Shared Helper ───────────────────────────────────────────

    private function googleCall(Request $request, callable $action): JsonResponse
    {
        try {
            $google = GoogleUserService::forUser($request->user());
            $result = $action($google);

            return response()->json(['ok' => true, 'data' => $result]);

        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'token invalid') || str_contains($message, 'reconnect')) {
                return response()->json(['error' => $message, 'reconnect' => true], 401);
            }

            return response()->json(['error' => $message], 422);
        }
    }
}
