<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_notifications', function (Blueprint $table) {
            $table->id();
            $table->integer('manager_id');
            $table->integer('team_member_id');
            $table->string('source', 64);
            $table->string('source_ref', 128);
            $table->string('message', 255);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['manager_id', 'team_member_id', 'source', 'source_ref'], 'mgr_notif_dedup');
            $table->index(['manager_id', 'dismissed_at'], 'mgr_notif_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_notifications');
    }
};
