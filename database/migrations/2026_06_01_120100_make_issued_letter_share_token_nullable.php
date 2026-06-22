<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            // Drafts have no share token until they are finalized. The unique index
            // is preserved by the modify and MySQL allows multiple NULLs under it.
            $table->string('share_token', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable(false)->change();
        });
    }
};
