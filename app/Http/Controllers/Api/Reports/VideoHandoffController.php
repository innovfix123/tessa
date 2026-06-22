<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\CreativeUpload;
use App\Models\User;
use App\Models\VideoHandoff;
use App\Models\VideoHandoffReview;
use App\Services\ActivityLogService;
use App\Services\VideoAspectRatio;
use App\Services\VideoHandoffNaming;
use App\Services\VideoHandoffNotifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Video handoff pipeline endpoints. The raw videos themselves come in through
 * the normal creative-uploads flow on field_key 'ai_videos_generated'; this
 * controller only serves the week view and Anaz's reworked-video uploads.
 *
 * Access is gated by explicit user id rather than the role-based
 * ProjectRoleService. Anaz (#18) is the only editor; Krishnan (#20), the
 * admin viewers (JP/Ayush), and the content creators themselves get a
 * read-only view (creators see only their own row).
 */
class VideoHandoffController extends Controller
{
    /** Read-only viewers on top of Anaz + Krishnan (JP, Ayush). */
    private const ADMIN_VIEWER_IDS = [1, 4];

    /** App-level per-file ceiling — matches nginx client_max_body_size (400M). */
    private const MAX_MB = 1000;

    /** The three deliverable crops Anaz uploads per raw video. */
    public const RATIOS = ['1:1', '9:16', '16:9'];

    /**
     * GET /api/video-handoffs?week_key=YYYY-MM-DD
     * One row per content creator (every report-to-Krishnan user), each raw
     * video carrying its derived status + any reworked versions. Creators with
     * no uploads still render so Anaz can see who hasn't sent anything yet;
     * the frontend hides those zero-rows from Krishnan's read-only view.
     *
     * When the viewer is themselves a content creator, only their own row is
     * returned — they only see the status of their own videos.
     */
    public function index(Request $request): JsonResponse
    {
        $viewerId = (int) $request->user()->id;
        if (! $this->canView($viewerId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $weekKey = $this->resolveWeekKey((string) $request->query('week_key', ''));
        $start = Carbon::parse($weekKey);
        $end = $start->copy()->addDays(6);

        $creatorIds = VideoHandoffNotifier::creatorIds();
        $isCreatorViewer = in_array($viewerId, $creatorIds, true);
        $scopedIds = $isCreatorViewer ? [$viewerId] : $creatorIds;

        $creators = User::whereIn('id', $scopedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        $rawUploads = CreativeUpload::with(['handoffs.updater:id,name', 'reviews'])
            ->whereIn('user_id', $creators->pluck('id'))
            ->where('field_key', VideoHandoffNotifier::RAW_FIELD)
            ->whereBetween('report_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get();

        $rawsByCreatorDate = [];
        foreach ($rawUploads as $raw) {
            $dateKey = $raw->report_date->format('Y-m-d');
            $rawsByCreatorDate[$raw->user_id][$dateKey][] = $this->presentRaw($raw);
        }

        $rows = $creators
            ->map(fn (User $c) => [
                'creatorId' => $c->id,
                'creatorName' => $c->name,
                'isActive' => (bool) $c->is_active,
                'days' => $rawsByCreatorDate[$c->id] ?? [],
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'weekKey' => $weekKey,
            'canEdit' => $viewerId === VideoHandoffNotifier::ANAZ_USER_ID,
            'rows' => $rows,
        ]);
    }

    /**
     * GET /api/video-handoffs/zip?creator_id=N&date=YYYY-MM-DD
     * Bundles a creator's raw videos + Anaz's reworked versions for one day
     * into a single ZIP, with `raw/` and `reworked-by-anas/` subfolders. Used
     * by Anas to grab a batch in one go (download → rework locally → upload
     * the reworked folder back via the multi-file picker), and by creators to
     * grab Anas's reworked copies in one shot.
     */
    public function downloadZip(Request $request): JsonResponse|BinaryFileResponse
    {
        $viewerId = (int) $request->user()->id;
        if (! $this->canView($viewerId)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $creatorId = (int) $request->query('creator_id', 0);
        $date = trim((string) $request->query('date', ''));

        if ($creatorId <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'creator_id and date (YYYY-MM-DD) are required'], 422);
        }

        // A creator can only zip their own batch; Anaz/Krishnan/admins can zip any.
        if (VideoHandoffNotifier::isCreator($viewerId) && $viewerId !== $creatorId) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! VideoHandoffNotifier::isCreator($creatorId)) {
            return response()->json(['error' => 'Not a content creator'], 422);
        }

        $creator = User::find($creatorId);
        if (! $creator) {
            return response()->json(['error' => 'Creator not found'], 404);
        }

        $raws = CreativeUpload::with('handoffs')
            ->where('user_id', $creatorId)
            ->where('field_key', VideoHandoffNotifier::RAW_FIELD)
            ->where('report_date', $date)
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get();

        if ($raws->isEmpty()) {
            return response()->json(['error' => 'No videos found for this day'], 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vh_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return response()->json(['error' => 'Could not create zip'], 500);
        }

        $usedNames = [];
        foreach ($raws as $raw) {
            $abs = storage_path('app/public/' . $raw->file_path);
            if (is_file($abs)) {
                $zip->addFile($abs, 'raw/' . $this->uniqueZipName($usedNames, 'raw', $raw->file_name));
            }
            $ratioSeen = [];
            foreach ($raw->handoffs->sortBy('id')->values() as $h) {
                $absH = storage_path('app/public/' . $h->updated_file_path);
                if (! is_file($absH)) {
                    continue;
                }
                $key = $h->ratio ?? '';
                $idx = $ratioSeen[$key] ?? 0;
                $ratioSeen[$key] = $idx + 1;
                $reworkedName = VideoHandoffNaming::downloadName($raw, (string) $h->updated_file_type, $idx, $h->ratio)
                    ?? $h->updated_file_name;
                $zip->addFile($absH, 'reworked-by-anas/' . $this->uniqueZipName($usedNames, 'rew', $reworkedName));
            }
        }
        $zip->close();

        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($creator->name));
        $slug = trim($slug, '-') ?: 'creator';
        $zipName = $slug . '-' . $date . '.zip';

        ActivityLogService::log(
            $request->user()->id,
            'video_handoff_zip',
            "{$request->user()->name} downloaded zip of {$creator->name}'s videos for {$date}",
        );

        return response()->download($tmp, $zipName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * De-dupe filenames inside the zip so two raws with the same name don't
     * silently collapse to one entry. Scope-prefix the dedupe map so the same
     * filename can appear in both raw/ and reworked/ folders.
     */
    private function uniqueZipName(array &$usedNames, string $scope, string $name): string
    {
        $base = $name === '' ? 'video' : $name;
        $candidate = $base;
        $i = 1;
        while (isset($usedNames[$scope . '/' . $candidate])) {
            $dot = strrpos($base, '.');
            if ($dot === false) {
                $candidate = $base . ' (' . $i . ')';
            } else {
                $candidate = substr($base, 0, $dot) . ' (' . $i . ')' . substr($base, $dot);
            }
            $i++;
        }
        $usedNames[$scope . '/' . $candidate] = true;
        return $candidate;
    }

    /**
     * POST /api/video-handoffs — action=upload | delete (Anaz only).
     */
    public function store(Request $request): JsonResponse
    {
        return match ($request->input('action', '')) {
            'upload' => $this->handleUpload($request),
            'delete' => $this->handleDelete($request),
            'review' => $this->handleReview($request),
            default => response()->json(['error' => 'Unknown action'], 422),
        };
    }

    /**
     * Anaz uploads one reworked video against a creator's raw video. The
     * frontend sends one file per request (loops for multi-select), matching
     * the creative-uploads pattern and staying under the post_max_size cap.
     */
    private function handleUpload(Request $request): JsonResponse
    {
        if ((int) $request->user()->id !== VideoHandoffNotifier::ANAZ_USER_ID) {
            return response()->json(['error' => 'Only Anaz can upload reworked videos'], 403);
        }

        $rawId = (int) $request->input('raw_upload_id', 0);
        if ($rawId <= 0) {
            return response()->json(['error' => 'raw_upload_id is required'], 422);
        }

        $raw = CreativeUpload::find($rawId);
        if (! $raw || $raw->field_key !== VideoHandoffNotifier::RAW_FIELD || ! $raw->file_path) {
            return response()->json(['error' => 'Raw video not found'], 404);
        }
        if (! VideoHandoffNotifier::isCreator((int) $raw->user_id)) {
            return response()->json(['error' => 'Raw video is not from a content creator'], 422);
        }

        // Each raw is delivered in three crops; the box the file was dropped into
        // declares its intended ratio. Verified against the file itself below.
        $intended = (string) $request->input('ratio', '');
        if (! in_array($intended, self::RATIOS, true)) {
            return response()->json(['error' => 'A valid ratio (1:1, 9:16 or 16:9) is required'], 422);
        }

        $request->validate(['file' => 'required|file']);
        $file = $request->file('file');

        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        if (! str_starts_with($mime, 'video/')) {
            return response()->json(['error' => 'Only video files are allowed'], 422);
        }
        if ($file->getSize() > self::MAX_MB * 1024 * 1024) {
            return response()->json(['error' => 'File too large. Max: ' . self::MAX_MB . 'MB'], 422);
        }

        $reportDate = $raw->report_date->format('Y-m-d');

        // Store first, then read the ACTUAL aspect ratio with ffprobe. A box only
        // accepts its own shape, so a mismatch (or an unreadable ratio) is rejected
        // and the just-stored blob deleted so we never leave an orphan behind.
        $path = $file->store('video_handoffs/' . $reportDate, 'public');
        $detected = VideoAspectRatio::classify(storage_path('app/public/' . $path));
        if ($detected === null || $detected !== $intended) {
            Storage::disk('public')->delete($path);
            $msg = $detected === null
                ? "Couldn't read this file's aspect ratio. Please upload a standard {$intended} video."
                : "This video is {$detected}, but the {$intended} box only accepts {$intended} videos.";

            return response()->json(['error' => $msg], 422);
        }

        // One video per ratio: a fresh upload into a filled box replaces the old
        // one (row + blob) so the box always holds the latest crop.
        foreach ($raw->handoffs()->where('ratio', $intended)->get() as $old) {
            if ($old->updated_file_path) {
                Storage::disk('public')->delete($old->updated_file_path);
            }
            $old->delete();
        }

        // Approval = the first rework. Freeze the standardized name's sequence on
        // the raw now (idempotent; no-op once assigned or for an unmapped creator)
        // so the deliverable can carry its {lang}_{code}_{NNN}_W{week} name. Done
        // only after the ratio passes, so a rejected upload never freezes a seq.
        VideoHandoffNaming::assignSequence($raw);

        $handoff = VideoHandoff::create([
            'raw_upload_id' => $raw->id,
            'updated_file_path' => $path,
            'updated_file_name' => $file->getClientOriginalName(),
            'updated_file_size' => $file->getSize(),
            'updated_file_type' => $ext,
            'ratio' => $detected,
            'updated_by' => $request->user()->id,
            'report_date' => $reportDate,
        ]);

        VideoHandoffNotifier::reworkNotice((int) $raw->user_id, $reportDate);

        ActivityLogService::log(
            $request->user()->id,
            'video_handoff_update',
            "{$request->user()->name} uploaded a {$detected} updated video {$handoff->updated_file_name} for raw #{$raw->id} ({$reportDate})",
        );

        return response()->json([
            'ok' => true,
            'handoff' => [
                'handoffId' => $handoff->id,
                'fileName' => $handoff->updated_file_name,
                'filePath' => asset('storage/' . $handoff->updated_file_path),
                'fileType' => $handoff->updated_file_type,
                'fileSize' => $handoff->updated_file_size,
                'ratio' => $handoff->ratio,
            ],
        ], 201);
    }

    /**
     * Anaz deletes one reworked video. This is also how "replace" works —
     * delete the old version, upload a fresh one.
     */
    private function handleDelete(Request $request): JsonResponse
    {
        if ((int) $request->user()->id !== VideoHandoffNotifier::ANAZ_USER_ID) {
            return response()->json(['error' => 'Only Anaz can delete reworked videos'], 403);
        }

        $handoffId = (int) $request->input('id', 0);
        if ($handoffId <= 0) {
            return response()->json(['error' => 'id is required'], 422);
        }

        $handoff = VideoHandoff::with('rawUpload')->find($handoffId);
        if (! $handoff) {
            return response()->json(['error' => 'Updated video not found'], 404);
        }

        $creatorId = (int) ($handoff->rawUpload?->user_id ?? 0);
        $reportDate = $handoff->report_date->format('Y-m-d');

        if ($handoff->updated_file_path) {
            Storage::disk('public')->delete($handoff->updated_file_path);
        }
        $handoff->delete();

        if ($creatorId > 0) {
            VideoHandoffNotifier::reworkNotice($creatorId, $reportDate);
        }

        ActivityLogService::log(
            $request->user()->id,
            'video_handoff_delete',
            "{$request->user()->name} deleted an updated video (#{$handoffId})",
        );

        return response()->json(['ok' => true]);
    }

    /**
     * The content creator approves Anaz's rework (verdict=approved, terminal)
     * or asks for changes (verdict=changes_requested + feedback, which DMs
     * Anaz so he can re-upload). Only the creator who uploaded the raw video
     * may pass judgment on it — Anaz/Krishnan/admins cannot.
     */
    private function handleReview(Request $request): JsonResponse
    {
        $rawId = (int) $request->input('raw_upload_id', 0);
        if ($rawId <= 0) {
            return response()->json(['error' => 'raw_upload_id is required'], 422);
        }

        $raw = CreativeUpload::with(['handoffs', 'reviews'])->find($rawId);
        if (! $raw || $raw->field_key !== VideoHandoffNotifier::RAW_FIELD || ! $raw->file_path) {
            return response()->json(['error' => 'Raw video not found'], 404);
        }

        if ((int) $raw->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => 'Only the creator who uploaded this video can review it'], 403);
        }

        if (! $this->ratioState($raw)['complete']) {
            return response()->json(['error' => 'All three ratios (1:1, 9:16, 16:9) must be uploaded before you can review.'], 422);
        }

        // Approved is terminal — nothing left to re-review.
        if ($this->deriveReviewState($raw) === 'approved') {
            return response()->json(['error' => 'This video is already approved'], 422);
        }

        $validated = $request->validate([
            'verdict' => 'required|in:approved,changes_requested',
            'feedback' => 'required_if:verdict,changes_requested|nullable|string|max:2000',
        ]);

        $verdict = $validated['verdict'];
        $feedback = $verdict === 'changes_requested'
            ? trim((string) ($validated['feedback'] ?? ''))
            : null;
        $reportDate = $raw->report_date->format('Y-m-d');

        VideoHandoffReview::create([
            'raw_upload_id' => $raw->id,
            'creator_id' => (int) $raw->user_id,
            'verdict' => $verdict,
            'feedback' => $feedback,
            'report_date' => $reportDate,
        ]);

        if ($verdict === 'changes_requested') {
            VideoHandoffNotifier::changesRequestedNotice((int) $raw->user_id, $raw->id, (string) $feedback, $reportDate);
        } else {
            VideoHandoffNotifier::approvedNotice((int) $raw->user_id, $raw->id, $reportDate);
        }

        ActivityLogService::log(
            $request->user()->id,
            'video_handoff_review',
            "{$request->user()->name} marked raw #{$raw->id} as "
                .($verdict === 'approved' ? 'approved' : 'needs changes')." ({$reportDate})",
        );

        return response()->json([
            'ok' => true,
            'reviewState' => $this->deriveReviewState($raw->fresh(['handoffs', 'reviews'])),
        ]);
    }

    private function presentRaw(CreativeUpload $raw): array
    {
        // Number versions WITHIN each ratio group so the rare second copy of a
        // ratio (or several legacy null-ratio rows) reads "v2" without a typed
        // crop ever being mislabelled by a sibling of a different ratio.
        $ratioSeen = [];
        $updatedVideos = $raw->handoffs
            ->sortBy('id')
            ->values()
            ->map(function (VideoHandoff $h) use ($raw, &$ratioSeen) {
                $key = $h->ratio ?? '';
                $idx = $ratioSeen[$key] ?? 0;
                $ratioSeen[$key] = $idx + 1;

                return [
                    'handoffId' => $h->id,
                    'fileName' => $h->updated_file_name,
                    'downloadName' => VideoHandoffNaming::downloadName($raw, (string) $h->updated_file_type, $idx, $h->ratio),
                    'filePath' => asset('storage/' . $h->updated_file_path),
                    'fileType' => $h->updated_file_type,
                    'fileSize' => $h->updated_file_size,
                    'ratio' => $h->ratio,
                    'updatedBy' => $h->updater?->name,
                    'updatedAt' => $h->created_at->toIso8601String(),
                ];
            });

        $ratioState = $this->ratioState($raw);

        // Creator-feedback thread (oldest first), surfaced to every viewer so
        // both sides can see the back-and-forth.
        $reviewHistory = $raw->reviews
            ->sortBy('created_at')
            ->values()
            ->map(fn (VideoHandoffReview $r) => [
                'verdict' => $r->verdict,
                'feedback' => $r->feedback,
                'at' => $r->created_at->toIso8601String(),
            ]);

        return [
            'rawId' => $raw->id,
            'fileName' => $raw->file_name,
            // The standardized deliverable name, assigned once Anaz reworks the
            // raw. Null before approval / for unmapped creators.
            'assignedName' => VideoHandoffNaming::nameFor($raw),
            'description' => $raw->content,
            'filePath' => asset('storage/' . $raw->file_path),
            'fileType' => $raw->file_type,
            'fileSize' => $raw->file_size,
            'uploadedAt' => $raw->created_at?->toIso8601String(),
            // "updated" only once the deliverable is complete (all 3 ratios, or a
            // grandfathered legacy raw); a partial upload stays "pending".
            'status' => $ratioState['complete'] ? 'updated' : 'pending',
            'updatedVideos' => $updatedVideos,
            // Per-ratio fill state drives the three boxes + the completeness gate.
            'ratiosFilled' => $ratioState['filled'],
            'hasLegacyVideos' => $ratioState['hasLegacy'],
            'complete' => $ratioState['complete'],
            // Creator approval loop — drives the Yes/No box and the cell colour.
            'reviewState' => $this->deriveReviewState($raw),
            'reviewHistory' => $reviewHistory,
        ];
    }

    /**
     * The per-ratio fill state of a raw's reworked videos. A raw is "complete"
     * when all three ratios (1:1, 9:16, 16:9) have a video, OR it carries legacy
     * pre-ratio videos (null ratio) — those predate the per-ratio boxes and are
     * grandfathered as done so the status/colour of historical rows never
     * regresses. Requires `handoffs` to be loaded on $raw.
     *
     * @return array{filled: array<string, bool>, hasLegacy: bool, complete: bool}
     */
    private function ratioState(CreativeUpload $raw): array
    {
        $ratios = $raw->handoffs->pluck('ratio');
        $filled = [
            '1:1' => $ratios->contains('1:1'),
            '9:16' => $ratios->contains('9:16'),
            '16:9' => $ratios->contains('16:9'),
        ];
        $hasLegacy = $ratios->contains(fn ($r) => $r === null || $r === '');
        $complete = ($filled['1:1'] && $filled['9:16'] && $filled['16:9']) || $hasLegacy;

        return ['filled' => $filled, 'hasLegacy' => $hasLegacy, 'complete' => $complete];
    }

    /**
     * Derive the creator-feedback state from the (handoffs, reviews) timeline:
     *   none              — Anaz hasn't reworked yet (no feedback box shown).
     *   awaiting_review   — a rework is unjudged, OR a rework is strictly newer
     *                       than the creator's last "changes" note (re-opened).
     *   changes_requested — latest verdict is "changes" and no newer rework.
     *   approved          — terminal; the creator is happy with it.
     * Requires `handoffs` and `reviews` to be loaded on $raw.
     */
    private function deriveReviewState(CreativeUpload $raw): string
    {
        $latestHandoff = $raw->handoffs->sortByDesc('created_at')->first();
        if (! $latestHandoff) {
            return 'none';
        }

        // The creator can only weigh in on a COMPLETE deliverable — all three
        // ratios present (or a grandfathered legacy raw). A partial upload shows
        // no Yes/No box and can't be reviewed.
        if (! $this->ratioState($raw)['complete']) {
            return 'none';
        }

        $latestReview = $raw->reviews->sortByDesc('created_at')->first();
        if (! $latestReview) {
            return 'awaiting_review';
        }
        if ($latestReview->verdict === 'approved') {
            return 'approved';
        }

        // changes_requested: a strictly-newer rework re-opens the loop.
        return $latestHandoff->created_at->gt($latestReview->created_at)
            ? 'awaiting_review'
            : 'changes_requested';
    }

    private function canView(int $userId): bool
    {
        return $userId === VideoHandoffNotifier::ANAZ_USER_ID
            || $userId === VideoHandoffNotifier::KRISHNAN_USER_ID
            || in_array($userId, self::ADMIN_VIEWER_IDS, true)
            || VideoHandoffNotifier::isCreator($userId);
    }

    /**
     * Snap any date to the Monday of its week; default to the current week.
     */
    private function resolveWeekKey(string $raw): string
    {
        $raw = trim($raw);
        $base = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)
            ? Carbon::parse($raw)
            : Carbon::now('Asia/Kolkata');

        return $base->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }
}
