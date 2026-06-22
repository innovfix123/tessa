<?php

namespace App\Http\Controllers\Api\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CreativeUpload;
use App\Models\DailyReport;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProjectRoleService;
use App\Services\VideoHandoffNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CreativeUploadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        $reportDate = trim($request->query('report_date', ''));
        $fieldKey = trim($request->query('field_key', ''));

        if ($userId <= 0 || $reportDate === '' || $fieldKey === '') {
            return response()->json(['error' => 'user_id, report_date, and field_key are required'], 422);
        }

        // For aggregators (managers whose subordinates share the same upload KPI),
        // show all team uploads
        $queryUserIds = [$userId];
        if ($this->isUploadAggregator($userId, $fieldKey)) {
            $subIds = User::where('reporting_manager_id', $userId)->pluck('id')->toArray();
            $queryUserIds = array_merge([$userId], $subIds);
        }

        $uploads = CreativeUpload::with('uploader:id,name')
            ->whereIn('user_id', $queryUserIds)
            ->where('report_date', $reportDate)
            ->where('field_key', $fieldKey)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'uploads' => $uploads->map(fn (CreativeUpload $u) => [
                'id' => $u->id,
                'file_name' => $u->file_name,
                'file_path' => $u->file_path ? asset('storage/' . $u->file_path) : null,
                'file_size' => $u->file_size,
                'file_type' => $u->file_type,
                'content' => $u->content,
                'folder_name' => $u->folder_name,
                'uploaded_by_name' => $u->uploader?->name,
                'created_at' => $u->created_at->toIso8601String(),
            ]),
            'count' => $uploads->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $action = $request->input('action', '');

        return match ($action) {
            'upload' => $this->handleUpload($request),
            'mark_no_video' => $this->handleMarkNoVideo($request),
            'save_text' => $this->handleSaveText($request),
            'delete' => $this->handleDelete($request),
            default => response()->json(['error' => 'Unknown action'], 422),
        };
    }

    /**
     * Daily-report entries for users in config/daily_report_owner_only.php are
     * OWNER-ONLY: managers/admins can VIEW them in the entry popup but cannot
     * edit or delete. Returns a 403 response when the requester is not the owner
     * of a protected user's entry; null otherwise. Content-creation team
     * (Krishnan's group) — see the config file.
     */
    private function denyIfOwnerOnly(Request $request, int $targetUserId): ?JsonResponse
    {
        $ownerOnly = array_map('intval', (array) config('daily_report_owner_only.user_ids', []));
        if (in_array($targetUserId, $ownerOnly, true) && (int) $request->user()->id !== $targetUserId) {
            return response()->json(['error' => 'These daily-report entries are owner-only — you can view them, but only the owner can edit or delete.'], 403);
        }

        return null;
    }

    /**
     * Folder name is a DISPLAY LABEL ONLY — it never touches a storage path.
     * Reduce to a basename, strip path separators and control chars, collapse
     * whitespace, and cap length so it can't carry traversal or layout-breaking
     * input. Returns null for empty/whitespace-only input.
     */
    private function sanitizeFolderName(?string $raw): ?string
    {
        $raw = basename(str_replace('\\', '/', (string) $raw)); // drop any "a/b/" prefix
        $raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);     // strip control chars
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));          // collapse whitespace
        if ($raw === '' || $raw === '.' || $raw === '..') {
            return null;
        }

        return mb_substr($raw, 0, 255);
    }

    private function handleUpload(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $request->input('user_id', 0);
        $reportDate = trim($request->input('report_date', ''));
        $fieldKey = trim($request->input('field_key', ''));

        if ($userId <= 0 || $reportDate === '' || $fieldKey === '') {
            return response()->json(['error' => 'user_id, report_date, and field_key are required'], 422);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
            return response()->json(['error' => 'report_date must be YYYY-MM-DD'], 422);
        }

        if (! ProjectRoleService::canAccessUser($user, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($resp = $this->denyIfOwnerOnly($request, $userId)) {
            return $resp;
        }

        $definition = KpiDefinition::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->whereIn('input_type', ['upload', 'textarea'])
            ->first();

        if (! $definition) {
            return response()->json(['error' => 'Field is not upload-enabled'], 422);
        }

        $request->validate([
            'file' => 'required|file',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $accept = trim((string) ($definition->upload_accept ?? ''));

        if ($accept === '' || $accept === '*' || $accept === '*/*') {
            // Any file type — KPI def opted out of restriction.
        } elseif (str_starts_with($accept, 'video/')) {
            // Accept any video by mime (covers phone captures, screen recordings, etc.)
            $mime = (string) $file->getMimeType();
            if (! str_starts_with($mime, 'video/')) {
                return response()->json(['error' => 'Only video files are allowed'], 422);
            }
        } else {
            $allowedExts = array_map('trim', explode(',', $accept));
            if (! in_array($ext, $allowedExts, true)) {
                return response()->json([
                    'error' => 'File type not allowed. Accepted: ' . $accept,
                ], 422);
            }
        }

        $maxBytes = ($definition->upload_max_mb ?? 10) * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            return response()->json([
                'error' => 'File too large. Max: ' . $definition->upload_max_mb . 'MB',
            ], 422);
        }

        $filePath = $file->store('creative_uploads/' . $reportDate, 'public');

        // Content creators can attach a description to a raw video at upload
        // time (video handoff pipeline). Stored in `content`, shown to Anaz and
        // Krishnan on the handoff cards. Other upload fields don't send it.
        $description = $fieldKey === VideoHandoffNotifier::RAW_FIELD
            ? (trim((string) $request->input('description', '')) ?: null)
            : null;

        // Optional display label when the creator uploaded a folder as a batch.
        // Sanitized to a basename — never used in the storage path above.
        $folderName = $this->sanitizeFolderName($request->input('folder_name'));

        $upload = CreativeUpload::create([
            'user_id' => $userId,
            'field_key' => $fieldKey,
            'report_date' => $reportDate,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_type' => $ext,
            'content' => $description,
            'folder_name' => $folderName,
            'uploaded_by' => $user->id,
        ]);

        $count = $this->syncDailyReportCount($userId, $fieldKey, $reportDate, $user->id);

        // Video handoff pipeline: a content creator's raw video upload posts a
        // "submitted to Anaz" notification on the Content Lead's dashboard.
        if ($fieldKey === VideoHandoffNotifier::RAW_FIELD && VideoHandoffNotifier::isCreator($userId)) {
            VideoHandoffNotifier::submissionNotice($userId, $reportDate);
        }

        ActivityLogService::log(
            $user->id,
            'creative_upload',
            "{$user->name} uploaded {$upload->file_name} for {$fieldKey} on {$reportDate}",
        );

        return response()->json([
            'ok' => true,
            'upload' => [
                'id' => $upload->id,
                'file_name' => $upload->file_name,
                'file_path' => asset('storage/' . $upload->file_path),
                'file_size' => $upload->file_size,
                'file_type' => $upload->file_type,
                'created_at' => $upload->created_at->toIso8601String(),
            ],
            'count' => $count,
        ], 201);
    }

    /**
     * "No video for this day" — a content creator with nothing to upload marks the
     * upload cell done instead of leaving it stuck on "Upload" (which blocks daily-
     * report sign-off). We simply sync the count: with zero uploads this writes
     * daily_reports.value = '0' (a non-empty value, so the cell reads as filled and
     * the Weekly total stays correct). If files actually exist it writes the true
     * count, so it can never wrongly zero a populated cell; an actual later upload
     * re-syncs the value automatically.
     */
    private function handleMarkNoVideo(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $request->input('user_id', 0);
        $reportDate = trim($request->input('report_date', ''));
        $fieldKey = trim($request->input('field_key', ''));

        if ($userId <= 0 || $reportDate === '' || $fieldKey === '') {
            return response()->json(['error' => 'user_id, report_date, and field_key are required'], 422);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
            return response()->json(['error' => 'report_date must be YYYY-MM-DD'], 422);
        }

        if (! ProjectRoleService::canAccessUser($user, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($resp = $this->denyIfOwnerOnly($request, $userId)) {
            return $resp;
        }

        $definition = KpiDefinition::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->where('input_type', 'upload')
            ->first();

        if (! $definition) {
            return response()->json(['error' => 'Not an upload field'], 422);
        }

        $count = $this->syncDailyReportCount($userId, $fieldKey, $reportDate, $user->id);

        return response()->json(['ok' => true, 'count' => $count], 200);
    }

    private function handleSaveText(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $request->input('user_id', 0);
        $reportDate = trim($request->input('report_date', ''));
        $fieldKey = trim($request->input('field_key', ''));
        $textContent = trim($request->input('content', ''));

        if ($userId <= 0 || $reportDate === '' || $fieldKey === '') {
            return response()->json(['error' => 'user_id, report_date, and field_key are required'], 422);
        }

        if ($textContent === '') {
            return response()->json(['error' => 'Content cannot be empty'], 422);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
            return response()->json(['error' => 'report_date must be YYYY-MM-DD'], 422);
        }

        if (! ProjectRoleService::canAccessUser($user, $userId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($resp = $this->denyIfOwnerOnly($request, $userId)) {
            return $resp;
        }

        $definition = KpiDefinition::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->where('input_type', 'textarea')
            ->first();

        if (! $definition) {
            return response()->json(['error' => 'Field is not textarea-enabled'], 422);
        }

        // Create a title from the first line of content (max 60 chars)
        $firstLine = strtok($textContent, "\n");
        $title = mb_substr(trim($firstLine), 0, 60);
        if (mb_strlen($firstLine) > 60) {
            $title .= '...';
        }

        $upload = CreativeUpload::create([
            'user_id' => $userId,
            'field_key' => $fieldKey,
            'report_date' => $reportDate,
            'file_path' => null,
            'file_name' => $title,
            'file_size' => mb_strlen($textContent),
            'file_type' => 'text',
            'content' => $textContent,
            'uploaded_by' => $user->id,
        ]);

        $count = $this->syncDailyReportCount($userId, $fieldKey, $reportDate, $user->id);

        ActivityLogService::log(
            $user->id,
            'creative_text_save',
            "{$user->name} saved script '{$title}' for {$fieldKey} on {$reportDate}",
        );

        return response()->json([
            'ok' => true,
            'upload' => [
                'id' => $upload->id,
                'file_name' => $upload->file_name,
                'content' => $upload->content,
                'file_size' => $upload->file_size,
                'file_type' => 'text',
                'created_at' => $upload->created_at->toIso8601String(),
            ],
            'count' => $count,
        ], 201);
    }

    private function handleDelete(Request $request): JsonResponse
    {
        $user = $request->user();
        $uploadId = (int) $request->input('id', 0);

        if ($uploadId <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }

        $upload = CreativeUpload::find($uploadId);
        if (! $upload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        if (! ProjectRoleService::canAccessUser($user, $upload->user_id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if ($resp = $this->denyIfOwnerOnly($request, (int) $upload->user_id)) {
            return $resp;
        }

        if ($upload->file_path) {
            Storage::disk('public')->delete($upload->file_path);
        }

        $userId = $upload->user_id;
        $fieldKey = $upload->field_key;
        $reportDate = $upload->report_date->format('Y-m-d');

        $isCreatorRawVideo = $fieldKey === VideoHandoffNotifier::RAW_FIELD
            && VideoHandoffNotifier::isCreator((int) $userId);

        // Video handoff pipeline: deleting a raw video cascades its handoff
        // rows away (FK), but the reworked video blobs must be cleaned up here
        // first — creative_uploads is hard-deleted, so this is the last chance.
        if ($isCreatorRawVideo) {
            foreach ($upload->handoffs as $handoff) {
                if ($handoff->updated_file_path) {
                    Storage::disk('public')->delete($handoff->updated_file_path);
                }
            }
        }

        $upload->delete();

        $count = $this->syncDailyReportCount($userId, $fieldKey, $reportDate, $user->id);

        if ($isCreatorRawVideo) {
            VideoHandoffNotifier::submissionNotice((int) $userId, $reportDate);
            VideoHandoffNotifier::reworkNotice((int) $userId, $reportDate);
        }

        return response()->json([
            'ok' => true,
            'count' => $count,
        ]);
    }

    private function syncDailyReportCount(int $userId, string $fieldKey, string $reportDate, int $updatedBy): int
    {
        // 1. Count this user's individual uploads
        $count = CreativeUpload::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->where('report_date', $reportDate)
            ->count();

        // 2. If this user is an aggregator, their DailyReport = team total
        $isAggregator = $this->isUploadAggregator($userId, $fieldKey);

        if ($isAggregator) {
            $this->syncManagerAggregateCount($userId, $fieldKey, $reportDate, $updatedBy);
        } else {
            DailyReport::updateOrCreate(
                ['user_id' => $userId, 'report_date' => $reportDate, 'field_key' => $fieldKey],
                ['value' => (string) $count, 'updated_by' => $updatedBy]
            );
        }

        // 3. If this user's manager is an aggregator for the same field, re-sync manager
        if (! $isAggregator) {
            $user = User::find($userId);
            if ($user && $user->reporting_manager_id) {
                if ($this->isUploadAggregator($user->reporting_manager_id, $fieldKey)) {
                    $this->syncManagerAggregateCount($user->reporting_manager_id, $fieldKey, $reportDate, $updatedBy);
                }
            }
        }

        return $count;
    }

    private function isUploadAggregator(int $userId, string $fieldKey): bool
    {
        $def = KpiDefinition::where('user_id', $userId)
            ->where('field_key', $fieldKey)
            ->whereIn('input_type', ['upload', 'textarea'])
            ->whereNull('deleted_at')
            ->first();

        if (! $def) {
            return false;
        }

        // Some managers opt out of narrative roll-up: their textarea fields
        // stay strictly per-person so each tab shows only that person's own
        // entries and own count. File uploads still aggregate normally.
        if ($def->input_type === 'textarea'
            && in_array($userId, config('daily_report_aggregation.no_textarea_aggregation_user_ids', []), true)) {
            return false;
        }

        $subIds = User::where('reporting_manager_id', $userId)->pluck('id')->toArray();
        if (empty($subIds)) {
            return false;
        }

        return KpiDefinition::whereIn('user_id', $subIds)
            ->where('field_key', $fieldKey)
            ->whereIn('input_type', ['upload', 'textarea'])
            ->whereNull('deleted_at')
            ->exists();
    }

    private function syncManagerAggregateCount(int $managerId, string $fieldKey, string $reportDate, int $updatedBy): void
    {
        $subIds = User::where('reporting_manager_id', $managerId)->pluck('id')->toArray();
        $teamIds = array_merge([$managerId], $subIds);

        $totalCount = CreativeUpload::whereIn('user_id', $teamIds)
            ->where('field_key', $fieldKey)
            ->where('report_date', $reportDate)
            ->count();

        DailyReport::updateOrCreate(
            ['user_id' => $managerId, 'report_date' => $reportDate, 'field_key' => $fieldKey],
            ['value' => (string) $totalCount, 'updated_by' => $updatedBy]
        );
    }
}
