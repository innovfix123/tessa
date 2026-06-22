<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bank_account_holder_name', 150)->nullable()->after('emergency_contact_number');
            $table->string('bank_account_number', 255)->nullable()->after('bank_account_holder_name');
            $table->string('bank_ifsc_code', 20)->nullable()->after('bank_account_number');
            $table->string('bank_passbook_path', 255)->nullable()->after('bank_ifsc_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bank_account_holder_name',
                'bank_account_number',
                'bank_ifsc_code',
                'bank_passbook_path',
            ]);
        });
    }
};
