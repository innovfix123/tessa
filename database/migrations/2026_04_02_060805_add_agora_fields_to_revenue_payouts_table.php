<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('audio_duration_sec')->default(0)->after('by_language');
            $table->unsignedBigInteger('video_duration_sec')->default(0)->after('audio_duration_sec');
            $table->unsignedBigInteger('audio_minutes')->default(0)->after('video_duration_sec');
            $table->unsignedBigInteger('video_minutes')->default(0)->after('audio_minutes');
            $table->decimal('agora_audio_cost_usd', 10, 2)->default(0)->after('video_minutes');
            $table->decimal('agora_video_cost_usd', 10, 2)->default(0)->after('agora_audio_cost_usd');
            $table->decimal('agora_total_cost_usd', 10, 2)->default(0)->after('agora_video_cost_usd');
            $table->decimal('agora_total_cost_inr', 12, 2)->default(0)->after('agora_total_cost_usd');
            $table->decimal('usd_inr_rate', 8, 2)->default(85)->after('agora_total_cost_inr');
        });
    }

    public function down(): void
    {
        Schema::table('revenue_payouts', function (Blueprint $table) {
            $table->dropColumn([
                'audio_duration_sec', 'video_duration_sec',
                'audio_minutes', 'video_minutes',
                'agora_audio_cost_usd', 'agora_video_cost_usd',
                'agora_total_cost_usd', 'agora_total_cost_inr', 'usd_inr_rate',
            ]);
        });
    }
};
