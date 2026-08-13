<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The historical fact that an offer was spent on an order. Append-only.
 *
 * The host has no such concept: it *infers* a use by counting rows in `orders`,
 * which is why a cancelled order can never give a use back, a failed payment
 * still spends the code, and "once per customer" cannot be expressed at all.
 *
 * `order_ref` is an **opaque string this module cannot resolve and never joins
 * to**. There is no foreign key to any order table and no orders table in this
 * package; the suite records a redemption against `ord_not_a_real_order` and
 * queries it back.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_redemptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);

            // Restricted rather than cascaded: an offer with history is not
            // deletable, because deleting it would silently destroy the record a
            // merchant reconciles against.
            $table->foreignId('offer_id')->constrained('promotions_offers')->restrictOnDelete();
            $table->foreignId('offer_revision_id')->constrained('promotions_offer_revisions')->restrictOnDelete();
            $table->foreignId('code_id')->nullable()->constrained('promotions_codes')->nullOnDelete();

            $table->string('order_ref', 128);

            // Nullable and clearable. Erasure redacts and keeps the shape: the
            // redemption, its lines and its release survive, because a merchant's
            // usage limits and reconciliation must not change because a shopper
            // exercised a right.
            $table->string('customer_ref', 128)->nullable();

            // 1, 2, 3 … per customer per offer. The unique index below is what
            // enforces a per-customer limit — a check-then-act in PHP is not a
            // constraint, and a model hook does not fire for query()->update().
            // Allocating the sequence under the index makes the bounds check that
            // follows it safe.
            $table->unsignedInteger('customer_sequence')->nullable();

            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->bigInteger('line_reduction_minor');

            // Separate from the lines, always. Shipping is taxed differently and
            // refunded differently from goods.
            $table->bigInteger('shipping_reduction_minor')->default(0);

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            // One offer is redeemed at most once per order. Every component is
            // server-supplied, which is why claiming needs no client idempotency
            // key: one would be strictly weaker than this.
            $table->unique(['tenant_id', 'offer_id', 'order_ref']);

            // The per-customer limit. NULL customer references collide freely,
            // which is right: no customer means no per-customer limit to enforce.
            $table->unique(['offer_id', 'customer_ref', 'customer_sequence']);

            $table->index(['tenant_id', 'order_ref']);
            $table->index(['offer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_redemptions');
    }
};
