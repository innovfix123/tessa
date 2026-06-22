<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERSON_TO_USER = [
        'sneha-ops' => 5,
        'anirudh-marketing' => 11,
        'anirudh-mktg' => 11,
        'anirudh' => 11,
        'tamil-sudar' => 12,
        'dhanush-thedal' => 13,
    ];

    public function up(): void
    {
        // 1. kpi_definitions: add user_id (INT to match users.id), migrate, drop old columns
        if (!Schema::hasColumn('kpi_definitions', 'user_id')) {
            Schema::table('kpi_definitions', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn('kpi_definitions', 'person_id')) {
            foreach (DB::table('kpi_definitions')->get() as $row) {
                $userId = $row->person_id ? (self::PERSON_TO_USER[$row->person_id] ?? null) : 5;
                if ($userId) {
                    DB::table('kpi_definitions')->where('id', $row->id)->update(['user_id' => $userId]);
                }
            }
            DB::table('kpi_definitions')->whereNull('user_id')->delete();
        }

        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->integer('user_id')->nullable(false)->change();
        });
        try {
            Schema::table('kpi_definitions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                throw $e;
            }
        }
        $colsToDrop = array_filter(['scope', 'person_id', 'person_name', 'person_role', 'project_name'], fn ($c) => Schema::hasColumn('kpi_definitions', $c));
        if (!empty($colsToDrop)) {
            Schema::table('kpi_definitions', fn ($t) => $t->dropColumn($colsToDrop));
        }

        try {
            Schema::table('kpi_definitions', function (Blueprint $table) {
                $table->index(['user_id', 'group_name']);
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) throw $e;
        }

        // 2. kpi_entries
        if (!Schema::hasColumn('kpi_entries', 'user_id')) {
            Schema::table('kpi_entries', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }
        if (Schema::hasColumn('kpi_entries', 'person_id')) {
        foreach (DB::table('kpi_entries')->get() as $row) {
            $userId = self::PERSON_TO_USER[$row->person_id] ?? null;
            if ($userId) {
                DB::table('kpi_entries')->where('id', $row->id)->update(['user_id' => $userId]);
            }
        }
        DB::table('kpi_entries')->whereNull('user_id')->delete();
        }
        if (Schema::hasColumn('kpi_entries', 'person_id')) {
            try {
                Schema::table('kpi_entries', function (Blueprint $table) {
                    $table->dropUnique('uniq_entry');
                });
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), "check that column/key exists") === false) throw $e;
            }
        }
        $entriesHasPersonId = Schema::hasColumn('kpi_entries', 'person_id');
        Schema::table('kpi_entries', function (Blueprint $table) use ($entriesHasPersonId) {
            $table->integer('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            if ($entriesHasPersonId) $table->dropColumn('person_id');
            $table->unique(['user_id', 'week_key', 'field_key']);
            $table->index(['week_key', 'user_id']);
        });

        // 3. kpi_targets
        if (!Schema::hasColumn('kpi_targets', 'user_id')) {
            Schema::table('kpi_targets', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }
        if (Schema::hasColumn('kpi_targets', 'person_id')) {
        foreach (DB::table('kpi_targets')->get() as $row) {
            $userId = self::PERSON_TO_USER[$row->person_id] ?? null;
            if ($userId) {
                DB::table('kpi_targets')->where('id', $row->id)->update(['user_id' => $userId]);
            }
        }
        DB::table('kpi_targets')->whereNull('user_id')->delete();
        }

        try {
            Schema::table('kpi_targets', function (Blueprint $table) {
                $table->dropUnique('uniq_target');
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), "check that column/key exists") === false) throw $e;
        }
        $targetsHasPersonId = Schema::hasColumn('kpi_targets', 'person_id');
        Schema::table('kpi_targets', function (Blueprint $table) use ($targetsHasPersonId) {
            $table->integer('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            if ($targetsHasPersonId) $table->dropColumn('person_id');
            $table->unique(['user_id', 'week_key', 'field_key']);
            $table->index(['week_key', 'user_id']);
        });

        // 4. daily_reports
        if (!Schema::hasColumn('daily_reports', 'user_id')) {
            Schema::table('daily_reports', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }
        if (Schema::hasColumn('daily_reports', 'person_id')) {
        foreach (DB::table('daily_reports')->get() as $row) {
            $userId = self::PERSON_TO_USER[$row->person_id] ?? null;
            if ($userId) {
                DB::table('daily_reports')->where('id', $row->id)->update(['user_id' => $userId]);
            }
        }
        DB::table('daily_reports')->whereNull('user_id')->delete();
        }

        try {
            Schema::table('daily_reports', function (Blueprint $table) {
                $table->dropUnique('uniq_daily_person_date_field');
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), "check that column/key exists") === false) throw $e;
        }
        $dailyHasPersonId = Schema::hasColumn('daily_reports', 'person_id');
        Schema::table('daily_reports', function (Blueprint $table) use ($dailyHasPersonId) {
            $table->integer('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            if ($dailyHasPersonId) $table->dropColumn('person_id');
            $table->unique(['user_id', 'report_date', 'field_key']);
            $table->index(['user_id', 'report_date']);
        });

        // 5. kpi_ceo_notes
        if (!Schema::hasColumn('kpi_ceo_notes', 'user_id')) {
            Schema::table('kpi_ceo_notes', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('id');
            });
        }
        if (Schema::hasColumn('kpi_ceo_notes', 'person_id')) {
        foreach (DB::table('kpi_ceo_notes')->get() as $row) {
            $userId = self::PERSON_TO_USER[$row->person_id] ?? null;
            if ($userId) {
                DB::table('kpi_ceo_notes')->where('id', $row->id)->update(['user_id' => $userId]);
            }
        }
        DB::table('kpi_ceo_notes')->whereNull('user_id')->delete();
        }

        try {
            Schema::table('kpi_ceo_notes', function (Blueprint $table) {
                $table->dropUnique('uniq_note');
            });
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), "check that column/key exists") === false) throw $e;
        }
        $notesHasPersonId = Schema::hasColumn('kpi_ceo_notes', 'person_id');
        Schema::table('kpi_ceo_notes', function (Blueprint $table) use ($notesHasPersonId) {
            $table->integer('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            if ($notesHasPersonId) $table->dropColumn('person_id');
            $table->unique(['user_id', 'week_key']);
            $table->index(['week_key', 'user_id']);
        });
    }

    public function down(): void
    {
        $userToPerson = [
            5 => 'sneha-ops',
            11 => 'anirudh-marketing',
            12 => 'tamil-sudar',
            13 => 'dhanush-thedal',
        ];

        foreach (['kpi_definitions', 'kpi_entries', 'kpi_targets', 'daily_reports', 'kpi_ceo_notes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('person_id', 50)->nullable()->after('id');
            });
            foreach (DB::table($table)->get() as $row) {
                $pid = $userToPerson[$row->user_id] ?? 'sneha-ops';
                DB::table($table)->where('id', $row->id)->update(['person_id' => $pid]);
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign(['user_id']);
                $t->dropColumn('user_id');
            });
        }

        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->string('scope', 20)->default('team');
            $table->string('person_name', 100)->nullable();
            $table->string('person_role', 100)->nullable();
            $table->string('project_name', 100)->nullable();
        });
    }
};
