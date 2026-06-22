<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->integer('head_user_id')->nullable();
            $table->unsignedBigInteger('parent_department_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('head_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_department_id')->references('id')->on('departments')->nullOnDelete();
        });

        // Seed initial departments
        $departments = [
            ['name' => 'Technology', 'slug' => 'technology'],
            ['name' => 'Marketing', 'slug' => 'marketing'],
            ['name' => 'Content', 'slug' => 'content'],
            ['name' => 'Operations', 'slug' => 'operations'],
            ['name' => 'Finance', 'slug' => 'finance'],
            ['name' => 'HR', 'slug' => 'hr'],
        ];
        foreach ($departments as $dept) {
            DB::table('departments')->insert(array_merge($dept, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
