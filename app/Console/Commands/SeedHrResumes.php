<?php

namespace App\Console\Commands;

use App\Models\FreelanceHrApplicant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeedHrResumes extends Command
{
    protected $signature = 'hr:seed-resumes';

    protected $description = 'Scan resume files in storage and seed freelance_hr_applicants table';

    public function handle(): int
    {
        $dir = storage_path('app/public/resumes/hr');
        if (!File::isDirectory($dir)) {
            $this->error("Directory not found: {$dir}");
            return self::FAILURE;
        }

        $extensions = ['pdf', 'doc', 'docx', ''];
        $files = File::files($dir);
        $count = 0;

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            $name = $file->getFilename();
            $isPdfLike = str_contains($name, '.pdf') || str_ends_with($name, '.PDF');
            if (!in_array($ext, $extensions, true) && $ext !== '' && !$isPdfLike) {
                continue;
            }

            $filename = $file->getFilename();
            $exists = FreelanceHrApplicant::where('resume_file', $filename)->exists();
            if ($exists) {
                continue;
            }

            $name = $this->extractNameFromFilename($filename);
            FreelanceHrApplicant::create([
                'name' => $name,
                'resume_file' => $filename,
                'status' => 'pending',
            ]);
            $count++;
            $this->line("Added: {$name} ({$filename})");
        }

        $this->info("Seeded {$count} new applicants.");
        return self::SUCCESS;
    }

    private function extractNameFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/\s*\(\d+\)\s*$/', '', $name);
        $name = preg_replace('/\s*\d{4,}\s*$/', '', $name);
        $name = preg_replace('/\s*(resume|cv|hr)\s*$/i', '', $name);
        $name = preg_replace('/^[^a-zA-Z]*(resume|cv|hr)[^a-zA-Z]*/i', '', $name);
        $name = preg_replace('/[-_]+/', ' ', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));
        $name = $name ?: pathinfo($filename, PATHINFO_FILENAME);
        return ucwords(strtolower($name)) ?: $filename;
    }
}
