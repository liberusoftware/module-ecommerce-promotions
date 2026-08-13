<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to an offer's terms, with the actor and when it happened.
 *
 * Append-only, and an **archive**: evaluation never reads it. A second readable
 * copy of the live terms would be the host's duplicated-column fault with better
 * provenance.
 *
 * A redemption records the revision it was evaluated under, and *that* is what
 * makes "an edit changes the future, not the past" a provable claim rather than a
 * promise.
 *
 * `occurred_at` is distinct from `created_at`: when the merchant made the change
 * is not when the row was written.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('promotions_offers')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');

            // The whole snapshot, as OfferTerms::toArray(). Read by humans and by
            // reconciliation, never by evaluation.
            $table->json('terms');

            $table->string('actor_ref', 128)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['offer_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_offer_revisions');
    }
};
