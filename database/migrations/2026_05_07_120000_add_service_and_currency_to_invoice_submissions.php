<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->string('service')->nullable()->after('vendor_name');
            $table->string('currency', 8)->default('INR')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_submissions', function (Blueprint $table) {
            $table->dropColumn(['service', 'currency']);
        });
    }
};
