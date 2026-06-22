<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('releases');
        Schema::dropIfExists('escalations');
    }

    public function down(): void
    {
        // Intentional no-op. The Releases and Escalations features were retired;
        // restoring the schema is not a real recovery path.
    }
};
