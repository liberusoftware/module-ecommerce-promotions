<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

/**
 * How much of an offer's reduction came off one line.
 *
 * Published because two callers need it and neither can re-derive it safely: the
 * tax engine spreads a discount pro-rata across lines with untaxable lines in the
 * denominator, and refunding one line of a discounted order requires knowing how
 * much of the discount that line carried.
 */
final readonly class LineAllocation
{
    public function __construct(
        public string $lineRef,
        public string $productRef,
        public int $amountMinor,
    ) {}

    /** @return array{line_ref: string, product_ref: string, amount_minor: int} */
    public function toArray(): array
    {
        return [
            'line_ref' => $this->lineRef,
            'product_ref' => $this->productRef,
            'amount_minor' => $this->amountMinor,
        ];
    }
}
