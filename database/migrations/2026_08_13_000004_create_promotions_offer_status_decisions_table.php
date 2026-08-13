<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed an offer's status, when, and why — a decision record rather than a
 * boolean flipped in place.
 *
 * "Who paused the Black Friday sale, and when" is a question somebody asks at 9am
 * on Black Friday. The host's answer is `discounts.is_active`, which records
 * neither.
 *
 * The reason is a closed enum, because a free-text field is not something you can
 * group by.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_offer_status_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('promotions_offers')->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason', 32);
            $table->string('actor_ref', 128)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['offer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_offer_status_decisions');
    }
};
