<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\IssueCode;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;

/**
 * The host's `CheckoutCouponRevalidationTest` restated against this module.
 *
 * Those six cases are the specification of the behaviour, and the host is right
 * about all six: `CheckoutController` and `HeadlessCheckoutService` both
 * recompute the coupon against the live cart rather than trusting the applied
 * figure. What the host also does — and this module cannot — is keep the stale
 * number in the session beside the correct behaviour, so the next surface to read
 * it starts from the wrong copy.
 *
 * Here there is no copy to start from: an entitlement is derived on every quote
 * and there is nowhere to put one.
 */
it('has nowhere to store an entitlement, which is why one can never go stale', function () {
    $tables = array_filter(
        array_map(static fn (array $c): string => (string) $c['name'], Schema::getTables()),
        static fn (string $table): bool => str_starts_with($table, 'promotions_'),
    );

    foreach ($tables as $table) {
        expect($table)->not->toContain('entitlement')->not->toContain('quote')->not->toContain('applied');
    }
});

it('recomputes against the live basket rather than a figure from a bigger one', function () {
    // A 50% offer, applied when the basket was £100. The basket is now £40.
    activeOffer(percentageTerms(5000));

    $whenItWasBigger = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));
    $now = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 4000]]));

    expect($whenItWasBigger->totalMinor())->toBe(5000)
        ->and($now->totalMinor())->toBe(2000);
});

it('cannot drive a basket below zero, however stale the figure a caller is holding', function () {
    activeOffer(percentageTerms(5000));

    $basket = basket([['line-1', 'p-1', 1, 4000]]);
    $entitlement = (new QuoteBasket())(TENANT, $basket);

    expect($entitlement->totalMinor())->toBe(2000)
        ->and($entitlement->totalMinor())->toBeLessThanOrEqual($basket->subtotalMinor());
});

it('drops an offer that has expired since it was applied', function () {
    $offer = activeOffer(percentageTerms(5000, ['endsAt' => at('2026-08-12 23:59')]));

    $before = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]]), [], at('2026-08-12 09:00'));
    $after = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]]), [], at('2026-08-13 09:00'));

    expect($before->totalMinor())->toBe(2500)
        ->and($after->totalMinor())->toBe(0)
        ->and($after->skipReasonFor($offer->id))->toBe(RefusalReason::Ended);
});

it('drops an offer whose minimum the basket no longer meets', function () {
    $offer = activeOffer(new OfferTerms(
        name: 'Ten pounds off seventy-five',
        type: OfferType::FixedAmount,
        target: OfferTarget::Order,
        stacking: StackingMode::Stackable,
        valueAmount: gbp(1000),
        minimumSubtotal: gbp(7500),
    ));

    $met = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 8000]]));
    $shrunk = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 4000]]));

    expect($met->totalMinor())->toBe(1000)
        ->and($shrunk->totalMinor())->toBe(0)
        ->and($shrunk->skipReasonFor($offer->id))->toBe(RefusalReason::MinimumNotMet);
});

it('drops an offer whose one allowed use was spent between quoting and checking out', function () {
    $offer = activeOffer(percentageTerms(5000, ['maxRedemptions' => 1]));

    $quoted = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]]));
    expect($quoted->totalMinor())->toBe(2500);

    // Somebody else's order spends it.
    (new ClaimRedemption())(TENANT, $quoted->applied[0], 'someone-elses-order', 'GBP');

    $requoted = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]]));

    expect($requoted->totalMinor())->toBe(0)
        ->and($requoted->skipReasonFor($offer->id))->toBe(RefusalReason::Exhausted);
});

it('applies a still-valid offer at the recomputed amount, not the remembered one', function () {
    $offer = activeOffer(percentageTerms(1000));
    (new IssueCode())(TENANT, $offer->id, 'GOOD');

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), ['GOOD']);

    expect($entitlement->totalMinor())->toBe(1000)
        ->and($entitlement->honouredCodes)->toBe(['GOOD'])
        ->and($entitlement->applied[0]->code)->toBe('GOOD');
});

it('agrees with itself: the same basket quoted twice gives the same answer', function () {
    activeOffer(percentageTerms(3333));

    $lines = [['line-1', 'p-1', 3, 333], ['line-2', 'p-2', 1, 7]];

    expect((new QuoteBasket())(TENANT, basket($lines))->toArray())
        ->toBe((new QuoteBasket())(TENANT, basket($lines))->toArray());
});
