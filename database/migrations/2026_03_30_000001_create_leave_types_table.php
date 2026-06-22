<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 30)->unique();
            $table->unsignedInteger('default_days_per_year')->default(0);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('leave_types')->insert([
            ['name' => 'Casual Leave', 'slug' => 'casual', 'default_days_per_year' => 12, 'requires_approval' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sick Leave', 'slug' => 'sick', 'default_days_per_year' => 6, 'requires_approval' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Emergency Leave', 'slug' => 'emergency', 'default_days_per_year' => 3, 'requires_approval' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Work From Home', 'slug' => 'wfh', 'default_days_per_year' => 0, 'requires_approval' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
