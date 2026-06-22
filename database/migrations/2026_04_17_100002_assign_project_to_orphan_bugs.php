<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bugs')
            ->whereNull('project_id')
            ->update(['project_id' => 1]);
    }

    public function down(): void
    {
        // No reliable way to identify which were originally null
    }
};
