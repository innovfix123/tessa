<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    // Manual name matching for employees whose names don't exactly match
    private array $nameMap = [
        'Anas Akbar' => 'anaz@innovfix.in',
        'Ashok kumar Raja' => null, // not in tessa
        'Deeksha K' => 'deeksha@innovfix.in',
        'DHANUSHKUMAR G' => 'dhanush@innovfix.in',
        'Fida Taneem' => 'fida@innovfix.in',
        'Gousia Begum C' => 'gousia@innovfix.in',
        'Hariharan M' => null,
        'Kanmani Thangaraju' => null,
        'Laxmi Bokaria' => 'laxmi@innovfix.in',
        'Mayank Rathi' => null,
        'Praveen Raj' => null,
        'RANJINI' => 'ranjini@innovfix.in',
        'Reshma R' => 'reshma@innovfix.in',
        'Rishabh Kumar' => 'rishabh@innovfix.in',
        'Sneha Prem Pratap' => 'snehaintern@innovfix.in',
        'Sneha Sunojkumar' => 'sneha@innovfix.in',
        'Tiyasa Dutta' => 'tiyasa@innovfix.in',
        'Yuvanesh .V' => 'yuvanesh@innovfix.in',
        'Vishal' => null,
        'Barkha Singhal' => 'barkha@innovfix.in',
        'Anirudh' => 'anirudh@innovfix.in',
        'Maanasi Halemane' => 'maanasi@innovfix.in',
        'Elaya Muthu Krishnan M' => 'krishnan@innovfix.in',
        'Shoyab MSB' => 'shoyab@innovfix.in',
        'Sooraj P' => 'sooraj@innovfix.in',
        'Raksha Agrawal' => 'raksha@innovfix.in',
        'Siva Perumal' => 'perumal@innovfix.in',
        'Anindita Hazarika' => 'anindita@innovfix.in',
        'Disha Sree' => 'disha@innovfix.in',
        'SANIKA SANTHOSH' => null,
        'Tamil Arasan' => 'tamil@innovfix.in',
        'Marimuthu T' => 'mari@innovfix.in',
    ];

    private string $srcBase = '/var/www/innovfix_map/storage/app/employee-documents';
    private string $dstBase = '/var/www/Tessa/storage/app/public/employee-documents';

    private array $docFields = [
        'aadhar_front_path',
        'aadhar_back_path',
        'pan_path',
        'passport_photo_path',
        'tenth_marksheet_path',
        'twelfth_marksheet_path',
        'degree_certificate_path',
        'pg_certificate_path',
        'prev_company_offer_letter_path',
        'experience_relieving_letters_path',
        'last_3_months_salary_slips_path',
        'signed_offer_letter_path',
        'nda_path',
        'college_id_path',
    ];

    // pm_portal column => tessa column
    private array $docFieldMap = [
        'aadhar_front_path' => 'aadhar_front_path',
        'aadhar_back_path' => 'aadhar_back_path',
        'pan_path' => 'pan_path',
        'passport_photo_path' => 'passport_photo_path',
        'tenth_marksheet_path' => 'tenth_marksheet_path',
        'twelfth_marksheet_path' => 'twelfth_marksheet_path',
        'degree_certificate_path' => 'degree_certificate_path',
        'pg_certificate_path' => 'pg_certificate_path',
        'prev_company_offer_letter_path' => 'prev_offer_letter_path',
        'experience_relieving_letters_path' => 'experience_letters_path',
        'last_3_months_salary_slips_path' => 'salary_slips_path',
        'signed_offer_letter_path' => 'signed_offer_letter_path',
        'nda_path' => 'nda_path',
        'college_id_path' => 'college_id_path',
    ];

    public function up(): void
    {
        $employees = DB::connection('mysql')->select('SELECT * FROM pm_portal.employees ORDER BY id');
        $tessaUsers = DB::table('users')->get()->keyBy(fn ($u) => strtolower($u->email));

        // Also get pm_portal.users for joining_date, hourly_rate, designation
        $pmUsers = collect(DB::connection('mysql')->select('SELECT * FROM pm_portal.users ORDER BY id'))
            ->keyBy(fn ($u) => strtolower($u->email));

        $synced = 0;

        foreach ($employees as $emp) {
            // Find matching Tessa user
            $tessaEmail = $this->nameMap[$emp->full_name] ?? null;

            if ($tessaEmail === null) {
                // Try first name match
                $firstName = strtolower(explode(' ', trim($emp->full_name))[0]);
                foreach ($tessaUsers as $email => $tu) {
                    if (str_starts_with(strtolower($tu->name), $firstName)) {
                        $tessaEmail = $email;
                        break;
                    }
                }
            }

            if ($tessaEmail === null) {
                Log::info("PM Portal sync: skipping unmatched employee '{$emp->full_name}' (pm_id={$emp->id})");
                continue;
            }

            $tessaUser = $tessaUsers[strtolower($tessaEmail)] ?? null;
            if (! $tessaUser) {
                Log::info("PM Portal sync: Tessa user not found for email '{$tessaEmail}'");
                continue;
            }

            $tessaId = $tessaUser->id;

            // Build update data
            $update = [
                'personal_mobile' => $emp->personal_mobile ?: null,
                'personal_email' => $emp->personal_email ?: null,
                'employment_type' => $emp->employment_type ?: null,
                'designation' => $emp->role ?: null,
                'emergency_contact_name' => $emp->emergency_contact_name ?: null,
                'emergency_contact_number' => $emp->emergency_contact_number ?: null,
                'experienced' => $emp->experienced,
            ];

            // Merge joining_date, hourly_rate from pm_portal.users if available
            $pmUser = $pmUsers->first(fn ($u) => strtolower($u->email) === strtolower($tessaEmail));
            if ($pmUser) {
                if ($pmUser->joining_date) {
                    $update['joining_date'] = $pmUser->joining_date;
                }
                if ($pmUser->hourly_rate > 0) {
                    $update['hourly_rate'] = $pmUser->hourly_rate;
                }
                if ($pmUser->designation && ! $update['designation']) {
                    $update['designation'] = $pmUser->designation;
                }
            }

            // Copy document files
            $srcDir = $this->srcBase . '/' . $emp->id;
            $dstDir = $this->dstBase . '/' . $tessaId;

            if (File::isDirectory($srcDir)) {
                File::ensureDirectoryExists($dstDir);

                foreach ($this->docFieldMap as $pmCol => $tessaCol) {
                    $srcPath = $emp->$pmCol ?? null;
                    if (! $srcPath) {
                        continue;
                    }

                    // Source file: /var/www/innovfix_map/storage/app/{srcPath}
                    $srcFile = '/var/www/innovfix_map/storage/app/' . $srcPath;
                    if (! File::exists($srcFile)) {
                        continue;
                    }

                    $fileName = basename($srcPath);
                    $dstRelPath = 'employee-documents/' . $tessaId . '/' . $fileName;
                    $dstFile = $this->dstBase . '/' . $tessaId . '/' . $fileName;

                    if (! File::exists($dstFile)) {
                        File::copy($srcFile, $dstFile);
                    }

                    $update[$tessaCol] = $dstRelPath;
                }
            }

            // Handle consolidated_marksheets (JSON)
            if ($emp->consolidated_marksheets_paths) {
                $paths = json_decode($emp->consolidated_marksheets_paths, true);
                if (is_array($paths) && count($paths) > 0) {
                    $newPaths = [];
                    foreach ($paths as $srcPath) {
                        $srcFile = '/var/www/innovfix_map/storage/app/' . $srcPath;
                        if (File::exists($srcFile)) {
                            $fileName = basename($srcPath);
                            $dstFile = $this->dstBase . '/' . $tessaId . '/' . $fileName;
                            if (! File::exists($dstFile)) {
                                File::copy($srcFile, $dstFile);
                            }
                            $newPaths[] = 'employee-documents/' . $tessaId . '/' . $fileName;
                        }
                    }
                    if ($newPaths) {
                        $update['consolidated_marksheets'] = json_encode($newPaths);
                    }
                }
            }

            DB::table('users')->where('id', $tessaId)->update($update);
            $synced++;
            Log::info("PM Portal sync: synced '{$emp->full_name}' => tessa user {$tessaId} ({$tessaUser->name})");
        }

        Log::info("PM Portal sync complete: {$synced} employees synced");
    }

    public function down(): void
    {
        // Clear HR fields (but keep the columns — those are in the previous migration)
        DB::table('users')->update([
            'personal_mobile' => null,
            'personal_email' => null,
            'employment_type' => null,
            'designation' => null,
            'emergency_contact_name' => null,
            'emergency_contact_number' => null,
            'experienced' => null,
            'joining_date' => null,
            'hourly_rate' => null,
            'aadhar_front_path' => null,
            'aadhar_back_path' => null,
            'pan_path' => null,
            'passport_photo_path' => null,
            'tenth_marksheet_path' => null,
            'twelfth_marksheet_path' => null,
            'degree_certificate_path' => null,
            'pg_certificate_path' => null,
            'consolidated_marksheets' => null,
            'prev_offer_letter_path' => null,
            'experience_letters_path' => null,
            'salary_slips_path' => null,
            'signed_offer_letter_path' => null,
            'nda_path' => null,
            'college_id_path' => null,
        ]);
    }
};
