<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Detects the aspect ratio of a video file and classifies it into one of the
 * three handoff deliverable shapes — 1:1 (square), 9:16 (portrait), 16:9
 * (landscape) — using ffprobe (/usr/bin/ffprobe, confirmed present).
 *
 * Used to enforce the per-ratio upload boxes in the video handoff pipeline:
 * the 1:1 box only accepts a 1:1 video, etc. Dependency-free (shell-exec
 * ffprobe, like ResumeTextExtractor shells pdftotext). Never throws — returns
 * null when the ratio can't be read or doesn't match one of the three targets,
 * which the caller treats as a rejection.
 */
class VideoAspectRatio
{
    private const FFPROBE = '/usr/bin/ffprobe';

    /**
     * Relative tolerance on the width/height ratio. 4% comfortably separates the
     * three targets (0.5625 / 1.0 / 1.7778) while absorbing a few pixels of crop
     * (e.g. 1078x1920). 4:5, 4:3, 2:1 etc. fall outside all three -> null.
     */
    private const TOL = 0.04;

    /** Target width/height ratios for each named shape. */
    private const TARGETS = [
        '1:1' => 1.0,
        '9:16' => 0.5625,
        '16:9' => 1.77778,
    ];

    /**
     * Classify the video at $absPath. Returns '1:1' | '9:16' | '16:9', or null
     * when ffprobe fails, the file is unreadable, or the shape doesn't match
     * any of the three within tolerance.
     */
    public static function classify(string $absPath): ?string
    {
        [$w, $h] = self::dimensions($absPath);
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        return self::classifyWh($w, $h);
    }

    /**
     * Pure classifier for a known width/height (also drives the unit checks and
     * mirrors the frontend's vhClassifyWh). Returns the nearest target only if
     * within TOL, else null.
     */
    public static function classifyWh(int $w, int $h): ?string
    {
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        $ratio = $w / $h;
        $best = null;
        $bestErr = PHP_FLOAT_MAX;
        foreach (self::TARGETS as $name => $target) {
            $err = abs($ratio - $target) / $target; // relative error
            if ($err < $bestErr) {
                $bestErr = $err;
                $best = $name;
            }
        }

        return $bestErr <= self::TOL ? $best : null;
    }

    /**
     * Rotation-corrected [width, height] from ffprobe, or [0, 0] on any failure.
     *
     * @return array{0:int,1:int}
     */
    private static function dimensions(string $absPath): array
    {
        if (! is_readable($absPath)) {
            return [0, 0];
        }

        $cmd = self::FFPROBE
            .' -v error -select_streams v:0 -show_streams -of json '
            .escapeshellarg($absPath).' 2>/dev/null';

        try {
            $out = shell_exec($cmd);
        } catch (\Throwable $e) {
            Log::warning('VideoAspectRatio ffprobe failed', ['message' => $e->getMessage()]);

            return [0, 0];
        }

        if (! is_string($out) || $out === '') {
            return [0, 0];
        }

        $json = json_decode($out, true);
        $stream = $json['streams'][0] ?? null;
        if (! is_array($stream)) {
            return [0, 0];
        }

        $w = (int) ($stream['width'] ?? 0);
        $h = (int) ($stream['height'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            return [0, 0];
        }

        // A portrait phone clip is often stored as a landscape frame plus a
        // rotation flag; the *displayed* frame swaps w/h. ffmpeg exposes this
        // either as a Display Matrix side-data `rotation` (signed degrees) or a
        // legacy `tags.rotate`. Swap on an odd multiple of 90 degrees.
        if (self::rotationDegrees($stream) % 180 !== 0) {
            [$w, $h] = [$h, $w];
        }

        return [$w, $h];
    }

    private static function rotationDegrees(array $stream): int
    {
        foreach (($stream['side_data_list'] ?? []) as $sd) {
            if (isset($sd['rotation'])) {
                return abs((int) round((float) $sd['rotation'])) % 360;
            }
        }

        $tag = $stream['tags']['rotate'] ?? null;

        return $tag !== null ? abs((int) $tag) % 360 : 0;
    }
}
