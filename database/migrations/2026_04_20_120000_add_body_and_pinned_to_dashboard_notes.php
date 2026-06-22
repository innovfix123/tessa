<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->text('body')->nullable()->after('title');
            $table->boolean('is_pinned')->default(false)->after('body');
            $table->json('items')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->dropColumn(['body', 'is_pinned']);
            $table->json('items')->nullable(false)->change();
        });
    }
};
