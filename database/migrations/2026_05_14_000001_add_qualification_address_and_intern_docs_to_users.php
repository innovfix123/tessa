<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('qualification', 150)->nullable()->after('marital_status');
            $table->text('address')->nullable()->after('qualification');
            $table->string('esic_intern_decl_path', 500)->nullable()->after('resume_path');
            $table->string('insurance_policy_path', 500)->nullable()->after('esic_intern_decl_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'qualification',
                'address',
                'esic_intern_decl_path',
                'insurance_policy_path',
            ]);
        });
    }
};
