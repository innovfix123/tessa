<?php

namespace App\Services;

use App\Models\CreativeUpload;
use Carbon\Carbon;

/**
 * Standardized naming for the video handoff pipeline.
 *
 * Once Anaz (#18) reworks/approves a creator's raw video, the deliverable is
 * named {lang}_{NNN}_W{isoWeek}_{editor} — e.g. TA_001_W25_KI. The pieces:
 *   - lang + editor: per-creator, from config/video_creator_codes.php
 *   - NNN: a per-LANGUAGE, per-WEEK sequence that restarts at 001 each ISO
 *     week (shared across a language's creators, so the two Telugu creators
 *     draw from one weekly TE counter), frozen on the raw row
 *     (creative_uploads.handoff_seq) the first time it's approved
 *   - W{isoWeek}: ISO week of the raw video's report_date (the content week)
 * The crop's orientation letter (V/S/H) is appended at download time.
 *
 * Sivaranjani's Only Care videos drop the editor code: OC_TA_{NNN}_W{week}.
 *
 * Naming is assigned only at approval, never at the creator's initial upload —
 * a raw with handoff_seq still null has no name and keeps its original
 * filename. Companion to VideoHandoffNotifier (people + notifications).
 */
class VideoHandoffNaming
{
    /** Crop aspect ratio → single-letter orientation code used in filenames. */
    private const ORIENTATION_CODES = ['9:16' => 'V', '1:1' => 'S', '16:9' => 'H'];

    /**
     * The config map for a creator, or null if unmapped. A regular creator has
     * ['lang' => 'TA', 'editor' => 'KI']; an Only Care creator has
     * ['lang' => 'OC_TA', 'oc' => true] (no editor code).
     */
    public static function mappingFor(int $userId): ?array
    {
        $map = config('video_creator_codes', [])[$userId] ?? null;

        return is_array($map) && isset($map['lang']) && (isset($map['editor']) || ! empty($map['oc']))
            ? $map
            : null;
    }

    /**
     * Every creator id sharing a language code — the basis for the shared
     * per-language counter (Telugu's Disha + Haripriya both resolve to TL).
     *
     * @return int[]
     */
    public static function userIdsForLang(string $lang): array
    {
        $ids = [];
        foreach (config('video_creator_codes', []) as $userId => $map) {
            if (is_array($map) && ($map['lang'] ?? null) === $lang) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    /**
     * Freeze the per-language sequence number on a raw video the first time it
     * is approved. Idempotent: a raw that already has a number (e.g. Anaz
     * uploads a second reworked version, or deletes and re-adds one) keeps it,
     * so delivered filenames stay stable. No-op for unmapped creators.
     */
    public static function assignSequence(CreativeUpload $raw): void
    {
        if ($raw->handoff_seq !== null) {
            return;
        }

        $map = self::mappingFor((int) $raw->user_id);
        if (! $map) {
            return;
        }

        // Per-language counter that resets every ISO week: scope the max to the
        // Mon–Sun week of this raw's report_date, so each new week restarts at
        // 001. Telugu's two creators still share the weekly TL counter
        // (userIdsForLang), first-come first-serve within that week.
        $weekStart = Carbon::parse($raw->report_date)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $max = CreativeUpload::whereIn('user_id', self::userIdsForLang($map['lang']))
            ->where('field_key', VideoHandoffNotifier::RAW_FIELD)
            ->whereBetween('report_date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->max('handoff_seq');

        $raw->handoff_seq = ((int) $max) + 1;
        $raw->save();
    }

    /**
     * The canonical name for a raw video, or null if it has no assigned
     * sequence yet (not approved) or its creator is unmapped.
     */
    public static function nameFor(CreativeUpload $raw): ?string
    {
        $map = self::mappingFor((int) $raw->user_id);
        if (! $map || $raw->handoff_seq === null) {
            return null;
        }

        $seq = str_pad((string) $raw->handoff_seq, 3, '0', STR_PAD_LEFT);
        $week = 'W' . Carbon::parse($raw->report_date)->isoWeek();

        // Only Care videos drop the editor code (OC_TA_001_W25); everyone else
        // is {lang}_{NNN}_W{week}_{editor}, e.g. TA_001_W25_KI.
        return ! empty($map['oc'])
            ? implode('_', [$map['lang'], $seq, $week])
            : implode('_', [$map['lang'], $seq, $week, $map['editor']]);
    }

    /**
     * Canonical download filename for a reworked deliverable. Each raw is
     * delivered in up to three crops; the orientation letter (9:16 → V, 1:1 → S,
     * 16:9 → H) is appended so the three sit side by side, e.g. TA_001_W25_KI_V.
     * A ratio that somehow carries more than one version gets a trailing " v2",
     * " v3" … so a batch/zip doesn't collide. $ratio is null (or unrecognized)
     * for legacy pre-ratio rows — no suffix. Returns null when there's no
     * canonical name.
     */
    public static function downloadName(CreativeUpload $raw, string $ext, int $versionIdx = 0, ?string $ratio = null): ?string
    {
        $base = self::nameFor($raw);
        if ($base === null) {
            return null;
        }

        if ($ratio !== null && isset(self::ORIENTATION_CODES[$ratio])) {
            $base .= '_' . self::ORIENTATION_CODES[$ratio];
        }

        if ($versionIdx > 0) {
            $base .= ' v' . ($versionIdx + 1);
        }

        $ext = trim((string) $ext);

        return $ext === '' ? $base : $base . '.' . $ext;
    }
}
