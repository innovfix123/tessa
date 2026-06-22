<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN meow_sound_enabled SET DEFAULT 1');
        DB::table('users')->update(['meow_sound_enabled' => true]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN meow_sound_enabled SET DEFAULT 0');
    }
};
