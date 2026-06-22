<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleUserService
{
    private User $user;
    private string $token;

    public function __construct(User $user)
    {
        $this->user = $user;

        if (! $user->google_access_token) {
            throw new RuntimeException('Google not connected. Please connect your Google account first.');
        }

        // Auto-refresh if token expired
        if ($user->google_token_expires_at && Carbon::now()->gte($user->google_token_expires_at)) {
            $this->refreshToken();
        }

        $this->token = $user->google_access_token;
    }

    public static function forUser(User $user): self
    {
        return new self($user);
    }

    // ─── Token Refresh ───────────────────────────────────────────

    private function refreshToken(): void
    {
        if (! $this->user->google_refresh_token) {
            $this->user->disconnectGoogle();
            throw new RuntimeException('Google token expired and no refresh token. Please reconnect.');
        }

        $config = config('services.google.oauth');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $this->user->google_refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        $data = $response->json();

        if (! ($data['access_token'] ?? null)) {
            Log::error('Google token refresh failed', ['error' => $data['error'] ?? 'unknown']);
            $this->user->disconnectGoogle();
            throw new RuntimeException('Google token refresh failed. Please reconnect.');
        }

        $this->user->update([
            'google_access_token'     => $data['access_token'],
            'google_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
        ]);

        $this->user->refresh();
    }

    // ─── Core API Call ───────────────────────────────────────────

    private function apiCall(string $method, string $url, array $params = [], bool $isJson = true): array
    {
        $send = function ($request) use ($method, $url, $params, $isJson) {
            // Google's JSON endpoints require an object body; an empty PHP array
            // serializes to `[]` (a JSON array) and is rejected with "Root element
            // must be a message" (e.g. Sheets values:clear). Send `{}` instead.
            if ($isJson && $params === [] && in_array($method, ['post', 'put', 'patch'], true)) {
                return $request->withBody('{}', 'application/json')->send(strtoupper($method), $url);
            }

            return match ($method) {
                'get'    => $request->get($url, $params),
                'post'   => $isJson ? $request->post($url, $params) : $request->asForm()->post($url, $params),
                'put'    => $request->put($url, $params),
                'patch'  => $request->patch($url, $params),
                'delete' => $request->delete($url, $params),
                default  => throw new RuntimeException("Unsupported method: {$method}"),
            };
        };

        $response = $send(Http::withToken($this->token));

        if ($response->status() === 401) {
            // Try refresh once
            try {
                $this->refreshToken();
                $this->token = $this->user->google_access_token;
                $response = $send(Http::withToken($this->token));
            } catch (\Throwable $e) {
                throw new RuntimeException('Google token invalid. Please reconnect.');
            }
        }

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? "HTTP {$response->status()}";
            throw new RuntimeException("Google API error: {$error}");
        }

        return $response->json() ?? [];
    }

    // ─── Auth ────────────────────────────────────────────────────

    public function getUserInfo(): array
    {
        return $this->apiCall('get', 'https://www.googleapis.com/oauth2/v2/userinfo');
    }

    // ─── Gmail ───────────────────────────────────────────────────

    public function listMessages(int $maxResults = 20, ?string $query = null, ?string $pageToken = null): array
    {
        $params = ['maxResults' => $maxResults];
        if ($query) $params['q'] = $query;
        if ($pageToken) $params['pageToken'] = $pageToken;

        return $this->apiCall('get', 'https://gmail.googleapis.com/gmail/v1/users/me/messages', $params);
    }

    public function getMessage(string $messageId): array
    {
        return $this->apiCall('get', "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", [
            'format' => 'full',
        ]);
    }

    public function getMessageSnippets(array $messageIds): array
    {
        $messages = [];
        foreach (array_slice($messageIds, 0, 20) as $id) {
            try {
                $msg = $this->apiCall('get', "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$id}", [
                    'format' => 'metadata',
                    'metadataHeaders' => ['From', 'Subject', 'Date'],
                ]);

                $headers = [];
                foreach ($msg['payload']['headers'] ?? [] as $h) {
                    $headers[$h['name']] = $h['value'];
                }

                $messages[] = [
                    'id'      => $msg['id'],
                    'threadId' => $msg['threadId'] ?? null,
                    'snippet' => $msg['snippet'] ?? '',
                    'from'    => $headers['From'] ?? '',
                    'subject' => $headers['Subject'] ?? '',
                    'date'    => $headers['Date'] ?? '',
                    'labelIds' => $msg['labelIds'] ?? [],
                ];
            } catch (\Throwable $e) {
                // Skip failed messages
            }
        }

        return $messages;
    }

    /**
     * Create a Gmail draft in this user's own mailbox, optionally with a single
     * file attachment. Needs the gmail.compose scope — a user who connected
     * Google before that scope was added must reconnect (Disconnect + Connect
     * Google) or this 403s with "insufficient authentication scopes".
     *
     * From/Reply-To is this user, so the recipient's reply lands back with them;
     * they just open Drafts, review, and hit Send.
     *
     * @return array Gmail draft resource: ['id' => ..., 'message' => [...]].
     */
    public function createDraft(
        string $to,
        string $subject,
        string $bodyText,
        ?string $attachmentBytes = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = 'application/pdf',
    ): array {
        $raw = $this->buildRawMessage($to, $subject, $bodyText, $attachmentBytes, $attachmentName, $attachmentMime);

        return $this->apiCall('post', 'https://gmail.googleapis.com/gmail/v1/users/me/drafts', [
            'message' => ['raw' => $raw],
        ]);
    }

    /**
     * Build a base64url-encoded RFC 2822 message (multipart/mixed when an
     * attachment is supplied). Kept separate so it's testable without the API.
     */
    public function buildRawMessage(
        string $to,
        string $subject,
        string $bodyText,
        ?string $attachmentBytes = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = 'application/pdf',
    ): string {
        $eol = "\r\n";
        $fromEmail = $this->user->google_email ?: $this->user->email;
        $fromName = str_replace(['"', "\r", "\n"], '', (string) $this->user->name);

        $headers = [
            'From: "'.$fromName.'" <'.$fromEmail.'>',
            'To: '.str_replace(["\r", "\n"], '', $to),
            // RFC 2047 encode so non-ASCII subjects (names, ₹, em-dashes) survive.
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'MIME-Version: 1.0',
        ];

        if ($attachmentBytes !== null) {
            $boundary = 'tessa_'.bin2hex(random_bytes(8));
            $name = str_replace(['"', "\r", "\n"], '', $attachmentName ?: 'attachment');
            $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';
            $mimeBody = '--'.$boundary.$eol
                .'Content-Type: text/plain; charset="UTF-8"'.$eol
                .'Content-Transfer-Encoding: base64'.$eol.$eol
                .chunk_split(base64_encode($bodyText)).$eol
                .'--'.$boundary.$eol
                .'Content-Type: '.$attachmentMime.'; name="'.$name.'"'.$eol
                .'Content-Transfer-Encoding: base64'.$eol
                .'Content-Disposition: attachment; filename="'.$name.'"'.$eol.$eol
                .chunk_split(base64_encode($attachmentBytes)).$eol
                .'--'.$boundary.'--';
        } else {
            $headers[] = 'Content-Type: text/plain; charset="UTF-8"';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $mimeBody = chunk_split(base64_encode($bodyText));
        }

        $mime = implode($eol, $headers).$eol.$eol.$mimeBody;

        return rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    }

    // ─── Calendar ────────────────────────────────────────────────

    public function listEvents(string $timeMin, string $timeMax, int $maxResults = 50, ?string $calendarId = 'primary'): array
    {
        return $this->apiCall('get', "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", [
            'timeMin'      => $timeMin,
            'timeMax'      => $timeMax,
            'maxResults'   => $maxResults,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
        ]);
    }

    public function getEventsForDate(string $date, ?string $calendarId = 'primary'): array
    {
        $dateObj = Carbon::parse($date, 'Asia/Kolkata');
        $timeMin = $dateObj->startOfDay()->toIso8601String();
        $timeMax = $dateObj->copy()->endOfDay()->toIso8601String();

        $result = $this->listEvents($timeMin, $timeMax, 50, $calendarId);

        return array_map(function ($event) {
            $start = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
            $end   = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;

            return [
                'id'          => $event['id'],
                'title'       => $event['summary'] ?? 'No title',
                'start'       => $start,
                'end'         => $end,
                'start_minutes' => $start ? $this->isoToMinutes($start) : null,
                'end_minutes'   => $end ? $this->isoToMinutes($end) : null,
                'location'    => $event['location'] ?? null,
                'meet_link'   => $event['hangoutLink'] ?? null,
                'status'      => $event['status'] ?? 'confirmed',
                'attendees'   => array_map(fn ($a) => $a['email'] ?? '', $event['attendees'] ?? []),
            ];
        }, $result['items'] ?? []);
    }

    public function createEvent(string $calendarId, array $event): array
    {
        $params = $event;
        // Request conference (Google Meet) if not set
        if (! isset($params['conferenceData'])) {
            $params['conferenceData'] = [
                'createRequest' => [
                    'requestId' => uniqid('tessa-'),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $this->apiCall('post',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?conferenceDataVersion=1",
            $params
        );
    }

    public function deleteEvent(string $calendarId, string $eventId): array
    {
        return $this->apiCall('delete', "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/" . rawurlencode($eventId));
    }

    // ─── Calendar notes (personal Calendar section — all-day notes) ───

    /**
     * All events between two YYYY-MM-DD dates (inclusive), each normalized via
     * formatEvent() with an `all_day` flag and a local `date` for month-grid
     * grouping. Used by the Calendar section + the dashboard upcoming card.
     */
    public function getEventsForRange(string $from, string $to, ?string $calendarId = 'primary'): array
    {
        $tz = 'Asia/Kolkata';
        $timeMin = Carbon::parse($from, $tz)->startOfDay()->toIso8601String();
        $timeMax = Carbon::parse($to, $tz)->endOfDay()->toIso8601String();

        $result = $this->listEvents($timeMin, $timeMax, 250, $calendarId);

        return array_map(fn ($event) => $this->formatEvent($event), $result['items'] ?? []);
    }

    /**
     * Create an all-day "note" (a real Google Calendar event). Deliberately does
     * NOT go through createEvent() — that forces a Google Meet conference, which
     * is invalid for an all-day event. Google's all-day end.date is exclusive, so
     * a single-day note on D is start=D, end=D+1.
     */
    public function createNote(string $title, string $date, ?string $description = null, ?string $calendarId = 'primary'): array
    {
        $tz = 'Asia/Kolkata';
        $event = [
            'summary' => $title,
            'start'   => ['date' => Carbon::parse($date, $tz)->toDateString()],
            'end'     => ['date' => Carbon::parse($date, $tz)->addDay()->toDateString()],
        ];
        if ($description !== null && $description !== '') {
            $event['description'] = $description;
        }

        return $this->apiCall('post',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events",
            $event
        );
    }

    /**
     * Patch an existing note's title/description/date (PATCH = only the supplied
     * fields change). Moving a note keeps it all-day (start/end as exclusive dates).
     */
    public function updateNote(string $eventId, array $fields, ?string $calendarId = 'primary'): array
    {
        $patch = [];
        if (array_key_exists('title', $fields)) {
            $patch['summary'] = $fields['title'];
        }
        if (array_key_exists('description', $fields)) {
            $patch['description'] = (string) $fields['description'];
        }
        if (! empty($fields['date'])) {
            $tz = 'Asia/Kolkata';
            $patch['start'] = ['date' => Carbon::parse($fields['date'], $tz)->toDateString()];
            $patch['end']   = ['date' => Carbon::parse($fields['date'], $tz)->addDay()->toDateString()];
        }

        return $this->apiCall('patch',
            "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/" . rawurlencode($eventId),
            $patch
        );
    }

    /** Normalize a raw Google event for the Calendar UI (adds all_day + local date). */
    private function formatEvent(array $event): array
    {
        $tz = 'Asia/Kolkata';
        $allDay = isset($event['start']['date']);
        $startRaw = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
        $endRaw   = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;

        // All-day: the visible date is start.date (end.date is exclusive). Timed:
        // the local IST calendar date of the start instant.
        $date = $allDay
            ? ($event['start']['date'] ?? null)
            : ($startRaw ? Carbon::parse($startRaw)->timezone($tz)->toDateString() : null);

        return [
            'id'            => $event['id'] ?? null,
            'title'         => $event['summary'] ?? '(no title)',
            'all_day'       => $allDay,
            'date'          => $date,
            'start'         => $startRaw,
            'end'           => $endRaw,
            'start_minutes' => (! $allDay && $startRaw) ? $this->isoToMinutes($startRaw) : null,
            'end_minutes'   => (! $allDay && $endRaw) ? $this->isoToMinutes($endRaw) : null,
            'description'   => $event['description'] ?? null,
            'location'      => $event['location'] ?? null,
            'meet_link'     => $event['hangoutLink'] ?? null,
            'status'        => $event['status'] ?? 'confirmed',
            'html_link'     => $event['htmlLink'] ?? null,
        ];
    }

    // ─── Drive ───────────────────────────────────────────────────

    public function listFiles(int $pageSize = 20, ?string $query = null, ?string $pageToken = null): array
    {
        $params = [
            'pageSize' => $pageSize,
            'fields'   => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,webViewLink,iconLink,owners)',
            'orderBy'  => 'modifiedTime desc',
        ];
        if ($query) $params['q'] = $query;
        if ($pageToken) $params['pageToken'] = $pageToken;

        return $this->apiCall('get', 'https://www.googleapis.com/drive/v3/files', $params);
    }

    /** The Drive parent folder id(s) of a file/folder (for ancestry/containment checks). */
    public function fileParents(string $id): array
    {
        $res = $this->apiCall('get', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id), [
            'fields' => 'id,parents',
        ]);

        return $res['parents'] ?? [];
    }

    public function searchFiles(string $query): array
    {
        return $this->listFiles(20, "name contains '{$query}' or fullText contains '{$query}'");
    }

    /** Move a Drive file/folder to Trash (recoverable ~30 days). Mirrors deleteEvent()'s use of apiCall(). */
    public function trashFile(string $fileId): array
    {
        return $this->apiCall(
            'patch',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true',
            ['trashed' => true]
        );
    }

    // ─── Drive write (HR doc sync — Feature 6, via this user's token) ───

    /**
     * Find-or-create a child folder by exact name under $parentId. Returns the
     * folder id, or null on failure. Lands each employee's documents in their own
     * subfolder of the master HR folder (reused on later uploads via the stored id).
     */
    public function ensureChildFolder(string $parentId, string $name): ?string
    {
        $clean = trim($name) ?: 'Unnamed';
        $safe = str_replace("'", "\\'", $clean);
        $q = "name = '{$safe}' and '{$parentId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

        $found = $this->apiCall('get', 'https://www.googleapis.com/drive/v3/files', [
            'q' => $q,
            'fields' => 'files(id,name)',
            'spaces' => 'drive',
        ]);
        if (! empty($found['files'][0]['id'])) {
            return $found['files'][0]['id'];
        }

        $created = $this->apiCall('post', 'https://www.googleapis.com/drive/v3/files?fields=id', [
            'name' => $clean,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ]);

        return $created['id'] ?? null;
    }

    /**
     * Upload raw bytes as a file into $folderId (multipart). Returns the new file
     * id, or null on failure. multipart/related can't go through apiCall(), so it
     * uses the (constructor-refreshed) token directly.
     */
    public function uploadFileToFolder(string $folderId, string $name, string $bytes, string $mime): ?string
    {
        $boundary = 'tessa_' . bin2hex(random_bytes(8));
        $metadata = json_encode(['name' => $name, 'parents' => [$folderId]]);

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $bytes . "\r\n"
            . "--{$boundary}--";

        $resp = Http::withToken($this->token)
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->timeout(30)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if (! $resp->ok()) {
            Log::warning('GoogleUserService: drive upload failed', ['status' => $resp->status(), 'body' => $resp->json()]);

            return null;
        }

        return $resp->json('id');
    }

    // ─── Sheets write (HR master-sheet upsert — Feature 5, via this user's token) ───

    /** Read a whole tab's values (row 0 = headers). Returns [] on empty/failure. */
    public function readSheetValues(string $sheetId, string $tab): array
    {
        $res = $this->apiCall('get',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($tab));

        return $res['values'] ?? [];
    }

    /** Overwrite a single A1 range (e.g. "Sheet1!A5") with one row of values. */
    public function updateSheetRange(string $sheetId, string $range, array $row): void
    {
        $this->apiCall('put',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($range) . '?valueInputOption=RAW',
            ['values' => [$row]]);
    }

    /** Append one row to the end of a tab. */
    public function appendSheetRow(string $sheetId, string $tab, array $row): void
    {
        $this->apiCall('post',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($tab)
                . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
            ['values' => [$row]]);
    }

    // ─── Drive + Sheets write (Travel ledger — full-sheet rebuild via this user's token) ───

    /**
     * Find a non-trashed file by exact name directly under $folderId (optionally of a
     * given mimeType). Returns ['id'=>…, 'name'=>…] or null. Used to locate the master
     * ledger spreadsheet so it's created once and reused (idempotent by name).
     */
    public function findFile(string $folderId, string $name, ?string $mime = null): ?array
    {
        $safe = str_replace("'", "\\'", $name);
        $q = "name = '{$safe}' and '{$folderId}' in parents and trashed = false";
        if ($mime) {
            $q .= " and mimeType = '{$mime}'";
        }

        $res = $this->apiCall('get', 'https://www.googleapis.com/drive/v3/files', [
            'q' => $q,
            'fields' => 'files(id,name)',
            'spaces' => 'drive',
        ]);

        return $res['files'][0] ?? null;
    }

    /**
     * Create a blank Google Sheet named $title inside $folderId (Drive create with the
     * spreadsheet mime). Returns ['id'=>…, 'tab'=>firstTabTitle, 'gid'=>firstSheetId,
     * 'url'=>webViewLink] or null on failure.
     */
    public function createSpreadsheetInFolder(string $folderId, string $title): ?array
    {
        $created = $this->apiCall('post', 'https://www.googleapis.com/drive/v3/files?fields=id,webViewLink', [
            'name' => $title,
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
            'parents' => [$folderId],
        ]);
        $id = $created['id'] ?? null;
        if (! $id) {
            return null;
        }

        $first = $this->getSpreadsheetSheets($id)[0] ?? ['gid' => 0, 'title' => 'Sheet1'];

        return [
            'id' => $id,
            'tab' => $first['title'],
            'gid' => $first['gid'],
            'url' => $created['webViewLink'] ?? null,
        ];
    }

    /** A spreadsheet's tabs as [['gid'=>int, 'title'=>string], …] (first = leftmost). */
    public function getSpreadsheetSheets(string $sheetId): array
    {
        $res = $this->apiCall('get', "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}", [
            'fields' => 'sheets.properties(sheetId,title)',
        ]);

        return array_map(fn ($s) => [
            'gid' => (int) ($s['properties']['sheetId'] ?? 0),
            'title' => (string) ($s['properties']['title'] ?? 'Sheet1'),
        ], $res['sheets'] ?? []);
    }

    /** Clear all values in an A1 range (e.g. "Sheet1!A:Z"); cell formatting is untouched. */
    public function clearSheetValues(string $sheetId, string $range): void
    {
        $this->apiCall('post',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($range) . ':clear');
    }

    /** Write a 2-D block of values starting at $range (e.g. "Sheet1!A1"). */
    public function updateSheetValues(string $sheetId, string $range, array $rows, string $valueInputOption = 'USER_ENTERED'): void
    {
        $this->apiCall('put',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}/values/" . rawurlencode($range)
                . '?valueInputOption=' . $valueInputOption,
            ['values' => $rows]);
    }

    /** Apply a list of spreadsheets.batchUpdate requests (formatting, freezing, …). No-op when empty. */
    public function batchUpdateSpreadsheet(string $sheetId, array $requests): void
    {
        if (! $requests) {
            return;
        }
        $this->apiCall('post',
            "https://sheets.googleapis.com/v4/spreadsheets/{$sheetId}:batchUpdate",
            ['requests' => $requests]);
    }

    /** Share a Drive file/folder with an email (default reader), no notification email. */
    public function shareFile(string $fileId, string $email, string $role = 'reader'): void
    {
        $this->apiCall('post',
            "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions?sendNotificationEmail=false",
            ['role' => $role, 'type' => 'user', 'emailAddress' => $email]);
    }

    /** Make a Drive file viewable by anyone with the link (no sign-in required). */
    public function makeFilePublicLink(string $fileId): void
    {
        $this->apiCall('post',
            "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions?sendNotificationEmail=false",
            ['role' => 'reader', 'type' => 'anyone']);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function isoToMinutes(string $iso): ?int
    {
        try {
            $dt = Carbon::parse($iso)->timezone('Asia/Kolkata');
            return $dt->hour * 60 + $dt->minute;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
