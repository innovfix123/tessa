<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(false)->after('priority');
            $table->boolean('requires_attachment')->default(false)->after('is_mandatory');
            $table->string('requires_form_url', 1000)->nullable()->after('requires_attachment');
            $table->timestamp('proof_submitted_at')->nullable()->after('requires_form_url');
            $table->text('proof_note')->nullable()->after('proof_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'is_mandatory',
                'requires_attachment',
                'requires_form_url',
                'proof_submitted_at',
                'proof_note',
            ]);
        });
    }
};
