<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A code is a way of *reaching* an offer, not the offer itself.
 *
 * One offer may be reachable by many codes — a per-customer unique code, a
 * campaign code, a partner code — or by none at all, which is what an automatic
 * discount is. The host's `coupons.code` is the primary key of the concept in
 * everything but name, which is why it can express neither case.
 *
 * The grain is per merchant, for the reason the host's own
 * `2026_08_09_000002_scope_promo_codes_to_the_merchant_that_issued_them` gives: a
 * globally unique code is a land grab, not a correctness constraint — the first
 * merchant to issue SUMMER10 would take it from everyone else on the
 * installation. That migration had to reason about NULL owners colliding freely.
 * Here `tenant_id` is not nullable, so the question does not arise.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_codes', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->foreignId('offer_id')->constrained('promotions_offers')->cascadeOnDelete();

            // Stored and matched upper-cased, so SUMMER10 and summer10 are one
            // code rather than two rows that look like a merchant typo.
            $table->string('code', 64);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_codes');
    }
};
