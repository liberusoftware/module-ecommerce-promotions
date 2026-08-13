<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Promotions\Actions\CreateOffer;
use Liberu\Ecommerce\Promotions\Actions\DecideOfferStatus;
use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\BasketLine;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

const TENANT = 'merchant-1';

/**
 * Terms for a straightforward percentage-off-the-order offer.
 *
 * @param  array<string, mixed>  $overrides
 */
function percentageTerms(int $basisPoints = 2000, array $overrides = []): OfferTerms
{
    return new OfferTerms(
        name: $overrides['name'] ?? 'Percentage off',
        type: OfferType::Percentage,
        target: $overrides['target'] ?? OfferTarget::Order,
        stacking: $overrides['stacking'] ?? StackingMode::Stackable,
        valueBasisPoints: $basisPoints,
        minimumSubtotal: $overrides['minimumSubtotal'] ?? null,
        minimumQuantity: $overrides['minimumQuantity'] ?? null,
        productRefs: $overrides['productRefs'] ?? [],
        collectionRefs: $overrides['collectionRefs'] ?? [],
        customerGroupRefs: $overrides['customerGroupRefs'] ?? [],
        priority: $overrides['priority'] ?? 0,
        startsAt: $overrides['startsAt'] ?? null,
        endsAt: $overrides['endsAt'] ?? null,
        maxRedemptions: $overrides['maxRedemptions'] ?? null,
        maxRedemptionsPerCustomer: $overrides['maxRedemptionsPerCustomer'] ?? null,
    );
}

/** An offer that is live: created, then activated by a named actor. */
function activeOffer(OfferTerms $terms, string $tenantId = TENANT): Offer
{
    $offer = (new CreateOffer())($tenantId, $terms, 'staff-1');

    (new DecideOfferStatus())($tenantId, $offer->id, OfferStatus::Active, OfferStatusReason::MerchantActivated, 'staff-1');

    return $offer->refresh();
}

/**
 * A basket of lines given as `[lineRef, productRef, quantity, unit minor]`.
 *
 * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $lines
 */
function basket(array $lines, int $shippingMinor = 0, ?string $customerRef = null, string $currency = 'GBP'): Basket
{
    return new Basket(
        currency: $currency,
        lines: array_map(static fn (array $line): BasketLine => new BasketLine(...$line), $lines),
        shippingMinor: $shippingMinor,
        customerRef: $customerRef,
    );
}

function gbp(int $minor): Money
{
    return Money::fromMinor($minor, 'GBP');
}

function at(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when);
}
