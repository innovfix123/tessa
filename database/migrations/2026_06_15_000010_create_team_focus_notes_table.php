<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A manager's daily "creative category" / work-focus note, shown to their
        // direct reports on the dashboard + as a sign-in modal. One row per
        // (author, date); the team sees the author's most recent note
        // (carry-forward until they set a new one).
        Schema::create('team_focus_notes', function (Blueprint $table) {
            $table->id();
            $table->integer('author_id');      // setter (manager) — users.id is a signed int
            $table->date('focus_date');        // day the category was set for
            $table->string('category', 500);   // free-text focus line
            $table->timestamps();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['author_id', 'focus_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_focus_notes');
    }
};
