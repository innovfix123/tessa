<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'snehaprathap@innovfix.in')
            ->update(['email' => 'snehaintern@innovfix.in']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'snehaintern@innovfix.in')
            ->update(['email' => 'snehaprathap@innovfix.in']);
    }
};
