<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer: the merchant's standing rule.
 *
 * A new table rather than an adoption of the host's `discounts`. That table
 * carries the same fact in two columns five times over (`usage_limits` beside
 * `usage_limit`, `active_dates` beside `starts_at`/`ends_at`, and so on), holds
 * money in `decimal(10,2)`, and declares three relations naming schema no
 * migration in the host creates. Adopting a table is a choice, not an obligation;
 * docs/adoption.md carries the migration off it.
 *
 * `tenant_id` is an opaque, non-nullable merchant reference. It is not a foreign
 * key: this module owns no tenant.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_offers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 32);
            $table->string('target', 32);
            $table->string('stacking', 16);

            // The live status, and a cache of the newest row in
            // promotions_offer_status_decisions. Nothing writes it but
            // DecideOfferStatus, and RecomputeOfferStatus proves the two agree.
            $table->string('status', 16)->default('draft');

            // A rate is basis points, an integer. 20% is 2000. A rate held as
            // decimal:2 cannot express a third off, and rounding a rate before
            // applying it to money loses more than rounding the result.
            $table->integer('value_basis_points')->nullable();

            // Money is minor units plus a currency plus an exponent, together.
            $table->bigInteger('value_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('currency_exponent')->nullable();
            $table->bigInteger('minimum_subtotal_minor')->nullable();

            $table->integer('minimum_quantity')->nullable();
            $table->json('product_refs')->nullable();
            $table->json('collection_refs')->nullable();
            $table->json('customer_group_refs')->nullable();
            $table->integer('buy_quantity')->nullable();
            $table->integer('get_quantity')->nullable();

            // Evaluation order: ascending priority, ties broken by ascending id.
            // Explicit, because a merchant's revenue must not depend on row order.
            $table->integer('priority')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->integer('max_redemptions')->nullable();
            $table->integer('max_redemptions_per_customer')->nullable();

            // Claimed by a conditional update, never by count-then-insert. Zero
            // affected rows means exhausted, which is race-free without a lock.
            // A cached counter nobody can check is a number nobody should trust,
            // so RecomputeRedemptionsUsed re-derives it from the two append-only
            // tables and a test proves they agree.
            $table->unsignedInteger('redemptions_used')->default(0);

            $table->unsignedInteger('revision_number')->default(1);

            // Deliberately *not* a foreign key: promotions_offer_revisions points
            // back at this table, and a constraint in both directions is a cycle
            // no insert order satisfies.
            $table->unsignedBigInteger('current_revision_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'priority', 'id'], 'promotions_offers_evaluation_index');
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_offers');
    }
};
