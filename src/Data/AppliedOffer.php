<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Support\Allocator;

/**
 * One offer's contribution to an entitlement.
 *
 * The line allocation sums to {@see lineReductionMinor()} exactly, by the rule in
 * {@see Allocator}. Shipping is published
 * **separately** and is never folded into the lines, because shipping is taxed
 * differently and refunded differently from goods.
 */
final readonly class AppliedOffer
{
    /** @param list<LineAllocation> $lines */
    public function __construct(
        public int $offerId,
        public string $offerName,
        public OfferType $type,
        public StackingMode $stacking,
        public int $priority,
        public int $offerRevisionId,
        public array $lines,
        public int $shippingReductionMinor,
        public ?int $codeId = null,
        public ?string $code = null,
    ) {}

    public function lineReductionMinor(): int
    {
        return array_sum(array_map(static fn (LineAllocation $line): int => $line->amountMinor, $this->lines));
    }

    public function totalMinor(): int
    {
        return $this->lineReductionMinor() + $this->shippingReductionMinor;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'offer_id' => $this->offerId,
            'offer_name' => $this->offerName,
            'type' => $this->type->value,
            'stacking' => $this->stacking->value,
            'priority' => $this->priority,
            'offer_revision_id' => $this->offerRevisionId,
            'code_id' => $this->codeId,
            'code' => $this->code,
            'lines' => array_map(static fn (LineAllocation $line): array => $line->toArray(), $this->lines),
            'line_reduction_minor' => $this->lineReductionMinor(),
            'shipping_reduction_minor' => $this->shippingReductionMinor,
            'total_minor' => $this->totalMinor(),
        ];
    }
}
