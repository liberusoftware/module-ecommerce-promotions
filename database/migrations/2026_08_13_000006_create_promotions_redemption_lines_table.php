<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a redemption came off each line.
 *
 * Stored rather than re-derived, because the terms it was derived under can
 * change and a refund six weeks later still needs the number that was actually
 * used. `product_ref` is opaque and carries no foreign key.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('promotions_redemption_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_id')->constrained('promotions_redemptions')->cascadeOnDelete();
            $table->string('line_ref', 128);
            $table->string('product_ref', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at')->nullable();

            $table->unique(['redemption_id', 'line_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions_redemption_lines');
    }
};
