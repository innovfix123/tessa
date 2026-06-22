<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Employee lifecycle status
            $table->enum('employee_status', [
                'active', 'probation', 'notice_period', 'resigned', 'terminated', 'absconding', 'intern',
            ])->default('active')->after('is_active');

            // Exit tracking
            $table->date('exit_date')->nullable()->after('employee_status');
            $table->string('exit_reason', 500)->nullable()->after('exit_date');
            $table->date('last_working_date')->nullable()->after('exit_reason');
            $table->date('resignation_date')->nullable()->after('last_working_date');

            // Probation tracking
            $table->date('probation_start_date')->nullable()->after('resignation_date');
            $table->date('probation_end_date')->nullable()->after('probation_start_date');
            $table->date('confirmed_date')->nullable()->after('probation_end_date');

            // Intern tracking
            $table->date('internship_start_date')->nullable()->after('confirmed_date');
            $table->date('internship_end_date')->nullable()->after('internship_start_date');
            $table->decimal('stipend_amount', 10, 2)->nullable()->after('internship_end_date');
            $table->enum('intern_conversion_status', ['pending', 'converted', 'not_converted'])->nullable()->after('stipend_amount');
            $table->date('intern_conversion_date')->nullable()->after('intern_conversion_status');

            // Salary
            $table->decimal('monthly_salary', 12, 2)->nullable()->after('intern_conversion_date');
            $table->decimal('annual_ctc', 14, 2)->nullable()->after('monthly_salary');
            $table->integer('notice_period_days')->default(30)->after('annual_ctc');

            // Department & Designation (structured)
            $table->unsignedBigInteger('department_id')->nullable()->after('notice_period_days');
            $table->unsignedBigInteger('designation_id')->nullable()->after('department_id');

            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('designation_id')->references('id')->on('designations')->nullOnDelete();

            // Index for common queries
            $table->index('employee_status');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['designation_id']);
            $table->dropIndex(['employee_status']);
            $table->dropIndex(['department_id']);
            $table->dropColumn([
                'employee_status', 'exit_date', 'exit_reason', 'last_working_date', 'resignation_date',
                'probation_start_date', 'probation_end_date', 'confirmed_date',
                'internship_start_date', 'internship_end_date', 'stipend_amount',
                'intern_conversion_status', 'intern_conversion_date',
                'monthly_salary', 'annual_ctc', 'notice_period_days',
                'department_id', 'designation_id',
            ]);
        });
    }
};
