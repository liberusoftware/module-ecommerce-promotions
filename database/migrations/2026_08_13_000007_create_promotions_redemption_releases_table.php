<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giving a use back.
 *
 * A separate append-only record rather than a deletion or a status flip on the
 * redemption, so "spent then returned" and "never spent" stay distinguishable.
 * The unique index on `redemption_id` is what makes one redemption releasable at
 * most once — not a guard, which would not fire for `query()->update()`.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_redemption_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_id')->constrained('promotions_redemptions')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->string('actor_ref', 128)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique('redemption_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_redemption_releases');
    }
};
