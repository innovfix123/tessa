<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            // 'draft' (auto-saved, no PDF/token yet) vs 'issued' (finalized).
            // Existing rows backfill to 'issued' via the default.
            $table->string('status', 16)->default('issued')->index()->after('employee_category');

            // Drafts are incomplete and unissued: no PDF, no issue timestamp,
            // and recipient/role may still be blank while HR is typing.
            $table->string('pdf_path', 500)->nullable()->change();
            $table->string('recipient_name', 200)->nullable()->change();
            $table->string('recipient_email', 200)->nullable()->change();
            $table->string('role_title', 200)->nullable()->change();
            $table->timestamp('issued_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->string('pdf_path', 500)->nullable(false)->change();
            $table->string('recipient_name', 200)->nullable(false)->change();
            $table->string('recipient_email', 200)->nullable(false)->change();
            $table->string('role_title', 200)->nullable(false)->change();
            $table->timestamp('issued_at')->useCurrent()->nullable(false)->change();
        });
    }
};
