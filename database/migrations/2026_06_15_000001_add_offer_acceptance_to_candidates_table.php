<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer-acceptance tracking on candidates. In the re-sequenced hiring flow the
 * probation/offer letter goes out first; once the candidate replies accepting
 * (auto-detected from Gmail, or marked by HR) we stamp `offer_accepted_at` so
 * the pipeline surfaces the "Add to Team" step. `offer_accepted_via` records
 * whether it was detected automatically or marked by hand.
 *
 * No new stage — the candidate stays in `offer` until added to the team; the
 * flag just gates the CTA. user FKs follow the signed `integer` rule, but these
 * two columns need none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->timestamp('offer_accepted_at')->nullable()->after('generated_email');
            $table->string('offer_accepted_via', 16)->nullable()->after('offer_accepted_at'); // auto | manual
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['offer_accepted_at', 'offer_accepted_via']);
        });
    }
};
