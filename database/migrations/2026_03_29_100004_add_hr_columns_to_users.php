<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('personal_mobile', 20)->nullable()->after('is_active');
            $table->string('personal_email', 255)->nullable()->after('personal_mobile');
            $table->enum('employment_type', ['full_time', 'internship'])->nullable()->after('personal_email');
            $table->string('designation', 100)->nullable()->after('employment_type');
            $table->string('emergency_contact_name', 255)->nullable()->after('designation');
            $table->string('emergency_contact_number', 20)->nullable()->after('emergency_contact_name');
            $table->boolean('experienced')->nullable()->after('emergency_contact_number');
            $table->date('joining_date')->nullable()->after('experienced');
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('joining_date');

            // Document paths
            $table->string('aadhar_front_path', 500)->nullable()->after('hourly_rate');
            $table->string('aadhar_back_path', 500)->nullable()->after('aadhar_front_path');
            $table->string('pan_path', 500)->nullable()->after('aadhar_back_path');
            $table->string('passport_photo_path', 500)->nullable()->after('pan_path');
            $table->string('tenth_marksheet_path', 500)->nullable()->after('passport_photo_path');
            $table->string('twelfth_marksheet_path', 500)->nullable()->after('tenth_marksheet_path');
            $table->string('degree_certificate_path', 500)->nullable()->after('twelfth_marksheet_path');
            $table->string('pg_certificate_path', 500)->nullable()->after('degree_certificate_path');
            $table->json('consolidated_marksheets')->nullable()->after('pg_certificate_path');
            $table->string('prev_offer_letter_path', 500)->nullable()->after('consolidated_marksheets');
            $table->string('experience_letters_path', 500)->nullable()->after('prev_offer_letter_path');
            $table->string('salary_slips_path', 500)->nullable()->after('experience_letters_path');
            $table->string('signed_offer_letter_path', 500)->nullable()->after('salary_slips_path');
            $table->string('nda_path', 500)->nullable()->after('signed_offer_letter_path');
            $table->string('college_id_path', 500)->nullable()->after('nda_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'personal_mobile', 'personal_email', 'employment_type', 'designation',
                'emergency_contact_name', 'emergency_contact_number', 'experienced',
                'joining_date', 'hourly_rate',
                'aadhar_front_path', 'aadhar_back_path', 'pan_path', 'passport_photo_path',
                'tenth_marksheet_path', 'twelfth_marksheet_path', 'degree_certificate_path',
                'pg_certificate_path', 'consolidated_marksheets',
                'prev_offer_letter_path', 'experience_letters_path', 'salary_slips_path',
                'signed_offer_letter_path', 'nda_path', 'college_id_path',
            ]);
        });
    }
};
