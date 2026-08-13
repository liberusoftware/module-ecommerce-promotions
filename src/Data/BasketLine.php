<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use InvalidArgumentException;

/**
 * One line of a basket, as told to this module.
 *
 * Everything here arrives as an argument. Promotions is told the basket and
 * never fetches one — there is no seam for "read the cart", because a module
 * that can read a cart will eventually decide what is in it.
 *
 * `productRef` is opaque. This module never resolves it, never joins to it, and
 * has no foreign key to any product table; the whole suite runs against product
 * references nothing in its database has heard of.
 */
final readonly class BasketLine
{
    public function __construct(
        public string $lineRef,
        public string $productRef,
        public int $quantity,
        public int $unitAmountMinor,
    ) {
        if ($lineRef === '') {
            throw new InvalidArgumentException('A basket line needs a reference.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException("Basket line [{$lineRef}] has a quantity of [{$quantity}].");
        }

        if ($unitAmountMinor < 0) {
            throw new InvalidArgumentException("Basket line [{$lineRef}] has a negative unit amount.");
        }
    }

    public function subtotalMinor(): int
    {
        return $this->quantity * $this->unitAmountMinor;
    }
}
