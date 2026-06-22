<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-wide announcements (Feature 8). The first use is the celebratory
 * "🎉 new team member joined" card shown on every employee's dashboard for a
 * week after a hire is created. Unlike ManagerNotification (per-manager) and
 * DashboardNote (per-user), these are broadcast to everyone — the index query
 * is not scoped to a viewer. Per-user dismissal is client-side (localStorage),
 * so no dismissals table is needed for a transient 7-day card.
 *
 * FKs to users.id use signed integer (users.id is a signed int in this app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('new_joiner');
            $table->string('title');
            $table->text('body');
            $table->integer('subject_user_id')->nullable()->index(); // the person the announcement is about
            $table->integer('created_by')->nullable();               // who triggered it
            $table->timestamp('expires_at')->nullable()->index();    // null = never; index() drives the active query
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
