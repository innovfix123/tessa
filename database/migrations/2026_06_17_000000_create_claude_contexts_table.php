<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One immutable "Claude context" per employee per day: Claude's own
        // end-of-day summary of what the user worked on, pushed in over MCP via
        // the log_claude_context tool. Employees see their own; JP sees all.
        // Mirrors mcp_call_log conventions: users.id is a signed INT, and the
        // row is write-once so it carries created_at only (no updated_at).
        Schema::create('claude_contexts', function (Blueprint $table) {
            $table->id();
            // users.id is signed INT in this DB; match exactly for the FK.
            $table->integer('user_id');
            $table->date('context_date');
            $table->text('summary');
            // Optional short usage tags Claude may attach (e.g. ["coding","research"]).
            $table->json('categories')->nullable();
            // Always 'mcp' today; column lets us distinguish future sources.
            $table->string('source', 20)->default('mcp');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // One context per person per day — the write-once lock lives here.
            $table->unique(['user_id', 'context_date']);
            $table->index('context_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_contexts');
    }
};
