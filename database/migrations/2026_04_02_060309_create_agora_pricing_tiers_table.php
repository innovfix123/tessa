<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agora_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);           // 'audio' or 'video_hd'
            $table->unsignedBigInteger('min_minutes');  // lower bound (inclusive)
            $table->unsignedBigInteger('max_minutes')->nullable(); // upper bound (null = unlimited)
            $table->string('tier_label', 20);      // e.g. '0-100k', '>100k'
            $table->decimal('price_usd', 8, 4);   // USD per 1000 participant minutes
            $table->timestamps();

            $table->unique(['type', 'tier_label']);
        });

        // Seed the pricing from HiMa pricing PDF (29 Aug)
        $tiers = [
            // Audio
            ['audio', 0, 100000, '0-100k', 0.84],
            ['audio', 100001, 500000, '>100k', 0.80],
            ['audio', 500001, 1000000, '>500k', 0.78],
            ['audio', 1000001, 3000000, '>1M', 0.76],
            ['audio', 3000001, 10000000, '>3M', 0.69],
            ['audio', 10000001, 30000000, '>10M', 0.63],
            ['audio', 30000001, null, '>30M', 0.50],
            // Video HD
            ['video_hd', 0, 100000, '0-100k', 3.39],
            ['video_hd', 100001, 500000, '>100k', 3.22],
            ['video_hd', 500001, 1000000, '>500k', 3.15],
            ['video_hd', 1000001, 3000000, '>1M', 3.05],
            ['video_hd', 3000001, 10000000, '>3M', 2.78],
            ['video_hd', 10000001, 30000000, '>10M', 2.54],
            ['video_hd', 30000001, null, '>30M', 2.12],
        ];

        foreach ($tiers as [$type, $min, $max, $label, $price]) {
            DB::table('agora_pricing_tiers')->insert([
                'type' => $type,
                'min_minutes' => $min,
                'max_minutes' => $max,
                'tier_label' => $label,
                'price_usd' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agora_pricing_tiers');
    }
};
