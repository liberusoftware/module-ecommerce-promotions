<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use InvalidArgumentException;

/**
 * A basket at one moment, handed to this module for evaluation.
 *
 * It is never stored, never cached and never carried between requests. The
 * entitlement derived from it is perishable: a basket that shrinks loses the
 * entitlement it had. The host learned exactly half of this — it recomputes the
 * coupon at checkout, correctly, and still keeps the stale applied figure in the
 * session for the next surface to start from.
 */
final readonly class Basket
{
    /** @param list<BasketLine> $lines */
    public function __construct(
        public string $currency,
        public array $lines,
        public int $shippingMinor = 0,
        public ?string $customerRef = null,
        public int $currencyExponent = 2,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("[{$currency}] is not a three-letter currency code.");
        }

        if ($shippingMinor < 0) {
            throw new InvalidArgumentException('A basket cannot carry a negative shipping amount.');
        }

        $seen = [];

        foreach ($lines as $line) {
            if (isset($seen[$line->lineRef])) {
                throw new InvalidArgumentException("Basket line reference [{$line->lineRef}] appears twice.");
            }

            $seen[$line->lineRef] = true;
        }
    }

    public function subtotalMinor(): int
    {
        return array_sum(array_map(static fn (BasketLine $line): int => $line->subtotalMinor(), $this->lines));
    }

    public function quantity(): int
    {
        return array_sum(array_map(static fn (BasketLine $line): int => $line->quantity, $this->lines));
    }

    /** @return array<string, int> lineRef => subtotal, in basket order. */
    public function subtotalsByLine(): array
    {
        $subtotals = [];

        foreach ($this->lines as $line) {
            $subtotals[$line->lineRef] = $line->subtotalMinor();
        }

        return $subtotals;
    }

    public function line(string $lineRef): ?BasketLine
    {
        foreach ($this->lines as $line) {
            if ($line->lineRef === $lineRef) {
                return $line;
            }
        }

        return null;
    }
}
