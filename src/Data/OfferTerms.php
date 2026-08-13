<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Exceptions\InvalidOfferTerms;

/**
 * An offer's terms: what the merchant wrote down.
 *
 * One shape, three uses — the argument to authoring, the source of the live
 * queryable columns on `promotions_offers`, and the snapshot archived in
 * `promotions_offer_revisions`. That is deliberate. The host carries the same
 * fact in two columns five times over in one table (`usage_limits` json beside
 * `usage_limit` int, `active_dates` beside `starts_at`/`ends_at`,
 * `applies_once_per_customer` beside `once_per_customer`, and so on) and the code
 * reads one of each pair.
 *
 * **Evaluation never reads the revision archive.** A second readable copy of the
 * live terms is that same fault with better provenance.
 *
 * A percentage is **basis points**, an integer: 20% is 2000. A rate stored as
 * `decimal:2` cannot express a third off, and rounding a rate before applying it
 * to money loses more than rounding the result.
 */
final readonly class OfferTerms
{
    /**
     * @param  list<string>  $productRefs  Opaque product references; never resolved here.
     * @param  list<string>  $collectionRefs  Opaque collection references; resolved through a seam.
     * @param  list<string>  $customerGroupRefs  Opaque group or segment references; resolved through a seam.
     */
    public function __construct(
        public string $name,
        public OfferType $type,
        public OfferTarget $target,
        public StackingMode $stacking,
        public ?string $description = null,
        public ?int $valueBasisPoints = null,
        public ?Money $valueAmount = null,
        public ?Money $minimumSubtotal = null,
        public ?int $minimumQuantity = null,
        public array $productRefs = [],
        public array $collectionRefs = [],
        public array $customerGroupRefs = [],
        public ?int $buyQuantity = null,
        public ?int $getQuantity = null,
        public int $priority = 0,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?int $maxRedemptions = null,
        public ?int $maxRedemptionsPerCustomer = null,
    ) {
        $this->validate();
    }

    /** The currency this offer is denominated in, or null when it names no amount. */
    public function currency(): ?string
    {
        return $this->valueAmount?->currency ?? $this->minimumSubtotal?->currency;
    }

    public function currencyExponent(): ?int
    {
        return $this->valueAmount?->exponent ?? $this->minimumSubtotal?->exponent;
    }

    /** Whether evaluating this offer needs the customer-eligibility seam. */
    public function needsCustomerEligibility(): bool
    {
        return $this->customerGroupRefs !== [];
    }

    /** Whether evaluating this offer needs the product-grouping seam. */
    public function needsProductGrouping(): bool
    {
        return $this->target === OfferTarget::Collection;
    }

    private function validate(): void
    {
        if (trim($this->name) === '') {
            throw InvalidOfferTerms::because('An offer needs a name.');
        }

        if ($this->type->usesBasisPoints()) {
            if ($this->valueBasisPoints === null || $this->valueBasisPoints < 1 || $this->valueBasisPoints > 10000) {
                throw InvalidOfferTerms::because("A {$this->type->value} offer needs a rate between 1 and 10000 basis points.");
            }

            if ($this->valueAmount !== null) {
                throw InvalidOfferTerms::because("A {$this->type->value} offer carries a rate, not an amount.");
            }
        }

        if ($this->type === OfferType::FixedAmount) {
            if ($this->valueAmount === null || $this->valueAmount->minor < 1) {
                throw InvalidOfferTerms::because('A fixed-amount offer needs a positive amount.');
            }

            if ($this->valueBasisPoints !== null) {
                throw InvalidOfferTerms::because('A fixed-amount offer carries an amount, not a rate.');
            }
        }

        if ($this->type === OfferType::FreeShipping) {
            if ($this->target !== OfferTarget::Shipping) {
                throw InvalidOfferTerms::because('A free-shipping offer targets shipping.');
            }

            if ($this->valueAmount !== null || $this->valueBasisPoints !== null) {
                throw InvalidOfferTerms::because('A free-shipping offer carries no value; it removes the shipping charge.');
            }
        } elseif ($this->target === OfferTarget::Shipping) {
            throw InvalidOfferTerms::because('Only a free-shipping offer targets shipping.');
        }

        if ($this->type === OfferType::BuyXGetY) {
            if ($this->buyQuantity === null || $this->buyQuantity < 1 || $this->getQuantity === null || $this->getQuantity < 1) {
                throw InvalidOfferTerms::because('A buy-X-get-Y offer needs a positive buy and get quantity.');
            }
        } elseif ($this->buyQuantity !== null || $this->getQuantity !== null) {
            throw InvalidOfferTerms::because("A {$this->type->value} offer has no buy or get quantity.");
        }

        if ($this->target === OfferTarget::Product && $this->productRefs === []) {
            throw InvalidOfferTerms::because('An offer targeting products must name at least one.');
        }

        if ($this->target === OfferTarget::Collection && $this->collectionRefs === []) {
            throw InvalidOfferTerms::because('An offer targeting collections must name at least one.');
        }

        if ($this->minimumQuantity !== null && $this->minimumQuantity < 1) {
            throw InvalidOfferTerms::because('A minimum quantity below one is not a minimum.');
        }

        if ($this->maxRedemptions !== null && $this->maxRedemptions < 1) {
            throw InvalidOfferTerms::because('A total redemption limit below one is not a limit.');
        }

        if ($this->maxRedemptionsPerCustomer !== null && $this->maxRedemptionsPerCustomer < 1) {
            throw InvalidOfferTerms::because('A per-customer redemption limit below one is not a limit.');
        }

        if ($this->valueAmount !== null && $this->minimumSubtotal !== null) {
            $this->valueAmount->assertSameCurrency($this->minimumSubtotal);
        }

        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt <= $this->startsAt) {
            throw InvalidOfferTerms::because('An offer cannot end before it starts.');
        }
    }

    /** @return array<string, mixed> The archived snapshot. */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'target' => $this->target->value,
            'stacking' => $this->stacking->value,
            'value_basis_points' => $this->valueBasisPoints,
            'value_amount' => $this->valueAmount?->toArray(),
            'minimum_subtotal' => $this->minimumSubtotal?->toArray(),
            'minimum_quantity' => $this->minimumQuantity,
            'product_refs' => $this->productRefs,
            'collection_refs' => $this->collectionRefs,
            'customer_group_refs' => $this->customerGroupRefs,
            'buy_quantity' => $this->buyQuantity,
            'get_quantity' => $this->getQuantity,
            'priority' => $this->priority,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'max_redemptions' => $this->maxRedemptions,
            'max_redemptions_per_customer' => $this->maxRedemptionsPerCustomer,
        ];
    }
}
