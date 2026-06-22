<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->string('github_branch', 255)->nullable()->after('completed_at');
            $table->string('github_pr_url', 500)->nullable()->after('github_branch');
            $table->string('github_pr_status', 50)->nullable()->after('github_pr_url');
            $table->string('github_repo', 255)->nullable()->after('github_pr_status');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn(['github_branch', 'github_pr_url', 'github_pr_status', 'github_repo']);
        });
    }
};
