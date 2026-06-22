<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Pulls plain text out of a résumé file for AI extraction. Dependency-free:
 * PDF via the `pdftotext` CLI (already used by InvoiceSubmissionController),
 * .docx via ZipArchive (word/document.xml). .doc legacy is best-effort/empty
 * (the caller then marks extraction_status='failed'). Never throws — returns
 * '' on any problem so an upload is never blocked by extraction.
 */
class ResumeTextExtractor
{
    private const MAX_CHARS = 20000;

    public function fromFile(string $absPath, string $ext): string
    {
        $ext = strtolower($ext);
        try {
            $text = match ($ext) {
                'pdf' => $this->fromPdf($absPath),
                'docx' => $this->fromDocx($absPath),
                default => '',
            };
        } catch (\Throwable $e) {
            Log::warning('ResumeTextExtractor failed', ['ext' => $ext, 'message' => $e->getMessage()]);
            $text = '';
        }

        $text = preg_replace('/\R/', "\n", $text) ?? '';
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }

    private function fromPdf(string $path): string
    {
        if (! is_readable($path)) {
            return '';
        }
        $output = [];
        $exit = 0;
        exec('pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null', $output, $exit);
        return $exit === 0 ? implode("\n", $output) : '';
    }

    private function fromDocx(string $path): string
    {
        if (! class_exists(\ZipArchive::class) || ! is_readable($path)) {
            return '';
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return '';
        }
        // Paragraph/line-break tags → newlines, then drop the remaining markup.
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<w:(?:br|tab)\b[^>]*\/?>/', ' ', $xml) ?? $xml;
        $text = strip_tags($xml);
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
