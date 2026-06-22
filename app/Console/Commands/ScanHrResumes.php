<?php

namespace App\Console\Commands;

use App\Models\FreelanceHrApplicant;
use Illuminate\Console\Command;

class ScanHrResumes extends Command
{
    protected $signature = 'hr:scan-resumes';

    protected $description = 'Extract phone numbers and experience summary from HR resume PDFs';

    public function handle(): int
    {
        $applicants = FreelanceHrApplicant::all();
        $dir = storage_path('app/public/resumes/hr');
        $updated = 0;

        foreach ($applicants as $applicant) {
            $filePath = $dir . '/' . $applicant->resume_file;
            if (!file_exists($filePath)) {
                $this->warn("File not found: {$applicant->resume_file}");
                continue;
            }

            $text = $this->extractText($filePath, $applicant->resume_file);
            if (!$text) {
                $this->warn("Could not extract text: {$applicant->resume_file}");
                continue;
            }

            $phone = $this->extractPhone($text);
            $experience = $this->extractExperience($text);

            $changes = [];
            if ($phone && !$applicant->phone) {
                $applicant->phone = $phone;
                $changes[] = "phone={$phone}";
            }
            if ($experience && !$applicant->experience_summary) {
                $applicant->experience_summary = $experience;
                $changes[] = "exp=" . mb_substr($experience, 0, 60) . '...';
            }

            if ($changes) {
                $applicant->save();
                $updated++;
                $this->line("{$applicant->name}: " . implode(', ', $changes));
            }
        }

        $this->info("Updated {$updated} applicants.");
        return self::SUCCESS;
    }

    private function extractText(string $filePath, string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isPdf = $ext === 'pdf' || str_contains(strtolower($filename), '.pdf');

        if ($isPdf || $ext === '' || $ext === 'PDF') {
            $escaped = escapeshellarg($filePath);
            $output = shell_exec("pdftotext {$escaped} - 2>/dev/null");
            return $output ?: null;
        }

        return null;
    }

    private function extractPhone(string $text): ?string
    {
        $patterns = [
            '/(?:\+91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}/',
            '/(?:\+91[\s\-]?)?[6-9]\d{9}/',
            '/\b\d{3}[\s\-]\d{3}[\s\-]\d{4}\b/',
            '/\b\d{5}[\s\-]\d{5}\b/',
            '/(?:\+91[\s\-]?)?\d{10}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $phone = preg_replace('/[^\d+]/', '', $match[0]);
                if (strlen($phone) >= 10 && strlen($phone) <= 13) {
                    return $phone;
                }
            }
        }

        return null;
    }

    private function extractExperience(string $text): ?string
    {
        $lines = preg_split('/\r?\n/', $text);
        $cleanLines = array_filter($lines, fn ($l) => trim($l) !== '');
        $cleanLines = array_values($cleanLines);
        $fullText = implode(' ', $cleanLines);

        if (preg_match('/(\d+[\.\+]?\s*(?:years?|yrs?)[\s\w]*(?:experience|exp|in\s+hr|in\s+human\s+resource|in\s+recruitment|of\s+experience))/i', $fullText, $m)) {
            return $this->cleanSummary($m[1]);
        }

        if (preg_match('/((?:experience|summary|profile|objective|about)\s*[:\-–]?\s*)(.{20,150})/i', $fullText, $m)) {
            return $this->cleanSummary($m[2]);
        }

        $hrKeywords = ['hr', 'human resource', 'recruitment', 'talent acquisition', 'payroll', 'hiring', 'onboarding', 'staffing'];
        foreach ($cleanLines as $i => $line) {
            $lower = strtolower($line);
            foreach ($hrKeywords as $kw) {
                if (str_contains($lower, $kw) && strlen(trim($line)) > 20 && strlen(trim($line)) < 200) {
                    return $this->cleanSummary(trim($line));
                }
            }
        }

        foreach ($cleanLines as $line) {
            $trimmed = trim($line);
            if (strlen($trimmed) > 30 && strlen($trimmed) < 200 && !preg_match('/^[A-Z\s]{5,}$/', $trimmed)) {
                return $this->cleanSummary($trimmed);
            }
        }

        return null;
    }

    private function cleanSummary(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = preg_replace('/^[\s\-–:•]+/', '', $text);
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117) . '...';
        }
        return ucfirst($text);
    }
}
