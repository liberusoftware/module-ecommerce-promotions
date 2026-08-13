<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Actions\ReleaseRedemption;
use Liberu\Ecommerce\Promotions\Data\Entitlement;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Events\RedemptionRecorded;
use Liberu\Ecommerce\Promotions\Events\RedemptionReleased;
use Liberu\Ecommerce\Promotions\Exceptions\CustomerLimitReached;
use Liberu\Ecommerce\Promotions\Exceptions\OfferExhausted;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyRecorded;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyReleased;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Queries\RecomputeRedemptionsUsed;

/** Quote a £100 single-line basket. */
function quoteFor(?string $customerRef = null): Entitlement
{
    return (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: $customerRef));
}

/** Quote a £100 single-line basket and claim the first offer that applied. */
function claimOn(string $orderRef, ?string $customerRef = null): Redemption
{
    return (new ClaimRedemption())(TENANT, quoteFor($customerRef)->applied[0], $orderRef, 'GBP', customerRef: $customerRef);
}

it('records the redemption, its lines and the revision it was evaluated under', function () {
    Event::fake([RedemptionRecorded::class]);
    $offer = activeOffer(percentageTerms(2500));

    $redemption = claimOn('ord-1', 'cus-1');

    expect($redemption->line_reduction_minor)->toBe(2500)
        ->and($redemption->shipping_reduction_minor)->toBe(0)
        ->and($redemption->total()->toArray()['decimal'])->toBe('25.00')
        ->and($redemption->offer_revision_id)->toBe($offer->current_revision_id)
        ->and($redemption->lines)->toHaveCount(1)
        ->and($redemption->lines[0]->amount_minor)->toBe(2500)
        ->and($redemption->lines[0]->product_ref)->toBe('p-1');

    Event::assertDispatched(RedemptionRecorded::class);
});

it('claims a use by conditional update, and calls zero affected rows exhausted', function () {
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptions' => 2]));

    claimOn('ord-1');
    claimOn('ord-2');

    expect($offer->refresh()->redemptions_used)->toBe(2);

    // The gate stops the third at quote time; the counter is what makes it safe
    // when two requests get past the gate together.
    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));
    expect($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::Exhausted);
});

it('refuses the claim itself when the counter is already spent, with no lock anywhere', function () {
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptions' => 1]));
    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    // Two callers that both passed the gate — the second is stopped by the
    // UPDATE ... WHERE redemptions_used < max_redemptions affecting no rows.
    (new ClaimRedemption())(TENANT, $entitlement->applied[0], 'ord-1', 'GBP');

    expect(fn () => (new ClaimRedemption())(TENANT, $entitlement->applied[0], 'ord-2', 'GBP'))
        ->toThrow(OfferExhausted::class);

    expect($offer->refresh()->redemptions_used)->toBe(1)
        ->and(Redemption::query()->count())->toBe(1, 'The failed claim must have rolled its redemption back');
});

it('lets one offer be redeemed at most once per order, by the unique index', function () {
    activeOffer(percentageTerms(1000));

    claimOn('ord-1');

    expect(fn () => claimOn('ord-1'))->toThrow(RedemptionAlreadyRecorded::class);
    expect(claimOn('ord-2'))->toBeInstanceOf(Redemption::class);
});

it('enforces a per-customer limit at the gate, and again at the claim', function () {
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptionsPerCustomer' => 2]));

    // An entitlement is perishable. Holding on to this one buys nothing once the
    // allowance behind it is spent — which is the whole reason it is not stored.
    $stale = quoteFor('cus-1')->applied[0];

    claimOn('ord-1', 'cus-1');
    claimOn('ord-2', 'cus-1');

    expect(quoteFor('cus-1')->skipReasonFor($offer->id))->toBe(RefusalReason::CustomerLimitReached)
        ->and(fn () => (new ClaimRedemption())(TENANT, $stale, 'ord-3', 'GBP', customerRef: 'cus-1'))
        ->toThrow(CustomerLimitReached::class);

    // A different shopper is unaffected.
    expect(claimOn('ord-4', 'cus-2'))->toBeInstanceOf(Redemption::class);
});

it('gives a per-customer slot back when the redemption is released', function () {
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptionsPerCustomer' => 1]));

    $first = claimOn('ord-1', 'cus-1');

    expect(quoteFor('cus-1')->skipReasonFor($offer->id))->toBe(RefusalReason::CustomerLimitReached);

    (new ReleaseRedemption())($first->id, ReleaseReason::OrderCancelled, 'staff-1');

    expect(claimOn('ord-3', 'cus-1'))->toBeInstanceOf(Redemption::class)
        ->and($first->refresh()->customer_sequence)->toBeNull()
        ->and($first->lines)->toHaveCount(1, 'A release must not take the redemption away')
        ->and($first->release)->not->toBeNull('nor its release');
});

it('releases a use once and only once', function () {
    Event::fake([RedemptionReleased::class]);
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptions' => 1]));

    $redemption = claimOn('ord-1');

    (new ReleaseRedemption())($redemption->id, ReleaseReason::OrderRefunded, 'staff-1', 'Returned in full');

    expect($offer->refresh()->redemptions_used)->toBe(0)
        ->and(fn () => (new ReleaseRedemption())($redemption->id, ReleaseReason::MerchantReversed))
        ->toThrow(RedemptionAlreadyReleased::class);

    // And the freed use is genuinely usable again, which the host cannot do at all.
    expect(claimOn('ord-2'))->toBeInstanceOf(Redemption::class);

    Event::assertDispatched(RedemptionReleased::class);
});

it('never lets the counter go below zero', function () {
    $offer = activeOffer(percentageTerms(1000));
    $redemption = claimOn('ord-1');

    Offer::query()->whereKey($offer->id)->update(['redemptions_used' => 0]);

    (new ReleaseRedemption())($redemption->id, ReleaseReason::PaymentFailed);

    expect($offer->refresh()->redemptions_used)->toBe(0);
});

it('keeps the cached counter in step with the ledger, across claims and releases', function () {
    $offer = activeOffer(percentageTerms(1000));
    $recompute = new RecomputeRedemptionsUsed();

    expect($recompute->agrees($offer->refresh()))->toBeTrue();

    $first = claimOn('ord-1', 'cus-1');
    claimOn('ord-2', 'cus-2');
    claimOn('ord-3', 'cus-3');

    expect($offer->refresh()->redemptions_used)->toBe(3)
        ->and($recompute($offer->id))->toBe(3)
        ->and($recompute->agrees($offer))->toBeTrue();

    (new ReleaseRedemption())($first->id, ReleaseReason::OrderCancelled);

    expect($offer->refresh()->redemptions_used)->toBe(2)
        ->and($recompute($offer->id))->toBe(2)
        ->and($recompute->agrees($offer))->toBeTrue();
});

it('records shipping separately on the redemption, never folded into the lines', function () {
    activeOffer(new OfferTerms(
        name: 'Free delivery',
        type: OfferType::FreeShipping,
        target: OfferTarget::Shipping,
        stacking: StackingMode::Stackable,
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], shippingMinor: 499));
    $redemption = (new ClaimRedemption())(TENANT, $entitlement->applied[0], 'ord-1', 'GBP');

    expect($redemption->shipping_reduction_minor)->toBe(499)
        ->and($redemption->line_reduction_minor)->toBe(0)
        ->and($redemption->lines)->toHaveCount(0)
        ->and($redemption->totalMinor())->toBe(499);
});

it('refuses to claim against another merchant\'s offer', function () {
    activeOffer(percentageTerms(1000));
    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect(fn () => (new ClaimRedemption())('merchant-2', $entitlement->applied[0], 'ord-1', 'GBP'))
        ->toThrow(OfferNotFound::class);
});
