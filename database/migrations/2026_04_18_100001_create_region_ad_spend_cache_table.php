<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_ad_spend_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);
            $table->string('project', 50)->default('hima');
            $table->date('reporting_date');
            $table->string('language', 20);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['source', 'project', 'reporting_date', 'language'], 'rasc_unique');
            $table->index('reporting_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_ad_spend_cache');
    }
};
