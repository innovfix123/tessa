<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            $table->longText('content')->nullable()->after('file_type');
            $table->string('file_path', 500)->nullable()->change();
        });

        // Change ad_scripts_written to textarea type for content team
        KpiDefinition::whereIn('user_id', [20, 21, 22, 40])
            ->where('field_key', 'ad_scripts_written')
            ->update(['input_type' => 'textarea']);
    }

    public function down(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            $table->dropColumn('content');
        });

        KpiDefinition::whereIn('user_id', [20, 21, 22, 40])
            ->where('field_key', 'ad_scripts_written')
            ->update(['input_type' => 'upload']);
    }
};
