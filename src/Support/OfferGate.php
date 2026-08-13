<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Support;

use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesCustomerEligibility;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesProductGrouping;
use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\BasketLine;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;

/**
 * Whether one offer may reduce one basket at all, before any arithmetic.
 *
 * Every refusal is named. Nothing here throws and nothing here fails the request:
 * an offer that cannot be evaluated is refused **by name**, and the refusal names
 * the offer rather than taking the whole quote down with it.
 */
final class OfferGate
{
    public function __construct(
        private ?ResolvesCustomerEligibility $customers = null,
        private ?ResolvesProductGrouping $grouping = null,
    ) {}

    /**
     * @return RefusalReason|list<string> The reason it does not apply, or its
     *                                    qualifying line references in basket order.
     */
    public function admit(Offer $offer, Basket $basket, CarbonImmutable $at, bool $needsCode, bool $codePresented): RefusalReason|array
    {
        if ($needsCode && ! $codePresented) {
            return RefusalReason::CodeNotPresented;
        }

        if ($offer->starts_at !== null && $at->isBefore($offer->starts_at)) {
            return RefusalReason::NotYetStarted;
        }

        if ($offer->ends_at !== null && $at->isAfter($offer->ends_at)) {
            return RefusalReason::Ended;
        }

        if ($offer->currency !== null && $offer->currency !== $basket->currency) {
            return RefusalReason::CurrencyMismatch;
        }

        if ($offer->max_redemptions !== null && $offer->redemptions_used >= $offer->max_redemptions) {
            return RefusalReason::Exhausted;
        }

        $limit = $this->customerLimit($offer, $basket);

        if ($limit instanceof RefusalReason) {
            return $limit;
        }

        $eligibility = $this->eligibility($offer, $basket);

        if ($eligibility instanceof RefusalReason) {
            return $eligibility;
        }

        if ($offer->minimum_subtotal_minor !== null && $basket->subtotalMinor() < $offer->minimum_subtotal_minor) {
            return RefusalReason::MinimumNotMet;
        }

        if ($offer->minimum_quantity !== null && $basket->quantity() < $offer->minimum_quantity) {
            return RefusalReason::MinimumNotMet;
        }

        return $this->qualifyingLines($offer, $basket);
    }

    /**
     * A per-customer limit counts **live** redemptions: a released one has given
     * its use back, which is the whole point of a release.
     *
     * An offer that limits per customer and is quoted for nobody cannot have that
     * limit enforced, so it does not apply — an unenforceable control is not a
     * satisfied one.
     */
    private function customerLimit(Offer $offer, Basket $basket): ?RefusalReason
    {
        if ($offer->max_redemptions_per_customer === null) {
            return null;
        }

        if ($basket->customerRef === null) {
            return RefusalReason::CustomerNotEligible;
        }

        $live = Redemption::liveCustomerSlots($offer->id, $basket->customerRef);

        return $live >= $offer->max_redemptions_per_customer ? RefusalReason::CustomerLimitReached : null;
    }

    /**
     * The seam rule this wave states.
     *
     * An unbound optional seam is safe when its absence removes a *claim* and
     * unsafe when it removes a *control*. Both of this module's seams remove a
     * control — each one narrows who qualifies, so treating an unresolvable rule
     * as satisfied gives real money away. By wave 11's rule that reads as "fail
     * the request", and that is wrong here:
     *
     * **The blast radius of an unbound seam is the scope of the thing it
     * controls.** This seam controls *the offers that name a segment*, so its
     * absence fails those offers and only those. Failing the checkout of a
     * shopper who was not using a segmented offer, on a deployment that simply
     * has no segments, would be a refusal with nothing behind it.
     */
    private function eligibility(Offer $offer, Basket $basket): ?RefusalReason
    {
        $groups = $offer->customer_group_refs ?? [];

        if ($groups === []) {
            return null;
        }

        if (! $this->customers instanceof ResolvesCustomerEligibility) {
            return RefusalReason::EligibilityUnresolvable;
        }

        if ($basket->customerRef === null) {
            return RefusalReason::CustomerNotEligible;
        }

        foreach ($groups as $group) {
            if ($this->customers->isCustomerIn($basket->customerRef, $group)) {
                return null;
            }
        }

        return RefusalReason::CustomerNotEligible;
    }

    /** @return RefusalReason|list<string> */
    private function qualifyingLines(Offer $offer, Basket $basket): RefusalReason|array
    {
        $refs = match ($offer->target) {
            OfferTarget::Order, OfferTarget::Shipping => array_map(
                static fn (BasketLine $line): string => $line->lineRef,
                $basket->lines,
            ),
            OfferTarget::Product => $this->linesMatching(
                $basket,
                fn (BasketLine $line): bool => in_array($line->productRef, $offer->product_refs ?? [], true),
            ),
            OfferTarget::Collection => $this->grouping instanceof ResolvesProductGrouping
                ? $this->linesMatching($basket, fn (BasketLine $line): bool => $this->isInAnyCollection($line, $offer))
                : RefusalReason::EligibilityUnresolvable,
        };

        if ($refs instanceof RefusalReason) {
            return $refs;
        }

        // A shipping offer takes nothing off the lines, so it does not need any.
        if ($refs === [] && $offer->target !== OfferTarget::Shipping) {
            return RefusalReason::NoQualifyingLines;
        }

        return array_values($refs);
    }

    /**
     * @param  callable(BasketLine): bool  $matches
     * @return list<string>
     */
    private function linesMatching(Basket $basket, callable $matches): array
    {
        $refs = [];

        foreach ($basket->lines as $line) {
            if ($matches($line)) {
                $refs[] = $line->lineRef;
            }
        }

        return $refs;
    }

    private function isInAnyCollection(BasketLine $line, Offer $offer): bool
    {
        foreach ($offer->collection_refs ?? [] as $collection) {
            if ($this->grouping?->isProductIn($line->productRef, $collection) === true) {
                return true;
            }
        }

        return false;
    }
}
