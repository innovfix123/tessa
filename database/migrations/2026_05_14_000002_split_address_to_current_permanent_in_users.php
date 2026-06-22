<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('current_address')->nullable()->after('qualification');
            $table->text('permanent_address')->nullable()->after('current_address');
            $table->dropColumn('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('address')->nullable()->after('qualification');
            $table->dropColumn(['current_address', 'permanent_address']);
        });
    }
};
