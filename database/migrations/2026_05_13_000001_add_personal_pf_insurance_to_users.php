<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('blood_group', 5)->nullable()->after('gender');
            $table->string('marital_status', 20)->nullable()->after('blood_group');
            $table->string('nominee_name', 150)->nullable()->after('marital_status');
            $table->unsignedTinyInteger('nominee_age')->nullable()->after('nominee_name');
            $table->date('nominee_dob')->nullable()->after('nominee_age');
            $table->string('nominee_relation', 50)->nullable()->after('nominee_dob');
            $table->boolean('pf_applicable')->default(false)->after('nominee_relation');
            $table->string('pf_uan', 30)->nullable()->after('pf_applicable');
            $table->boolean('insurance_applicable')->default(false)->after('pf_uan');
            $table->string('insurance_number', 60)->nullable()->after('insurance_applicable');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'blood_group',
                'marital_status',
                'nominee_name',
                'nominee_age',
                'nominee_dob',
                'nominee_relation',
                'pf_applicable',
                'pf_uan',
                'insurance_applicable',
                'insurance_number',
            ]);
        });
    }
};
