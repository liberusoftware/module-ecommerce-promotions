<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Actions\CreateOffer;
use Liberu\Ecommerce\Promotions\Actions\IssueCode;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;

it('writes nothing at all', function () {
    $offer = activeOffer(percentageTerms(2000, ['maxRedemptions' => 5]));

    (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));
    (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($offer->refresh()->redemptions_used)->toBe(0)
        ->and(DB::table('promotions_redemptions')->count())->toBe(0);
});

it('allocates an order-level reduction across every line, summing to the total exactly', function () {
    activeOffer(percentageTerms(3333));

    $entitlement = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'p-1', 1, 1000],
        ['line-2', 'p-2', 3, 333],
        ['line-3', 'p-3', 1, 7],
    ]));

    // Subtotal 2006; a third off floors to 668. Every penny is attributed.
    expect($entitlement->totalMinor())->toBe(668)
        ->and(array_sum($entitlement->allocationByLine()))->toBe(668)
        ->and($entitlement->applied[0]->lineReductionMinor())->toBe(668);
});

it('allocates a product-targeted reduction only to the lines it names', function () {
    activeOffer(new OfferTerms(
        name: 'Half off the mugs',
        type: OfferType::Percentage,
        target: OfferTarget::Product,
        stacking: StackingMode::Stackable,
        valueBasisPoints: 5000,
        productRefs: ['mug'],
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'mug', 2, 1000],
        ['line-2', 'kettle', 1, 5000],
    ]));

    expect($entitlement->totalMinor())->toBe(1000)
        ->and($entitlement->allocationForLine('line-1'))->toBe(1000)
        ->and($entitlement->allocationForLine('line-2'))->toBe(0);
});

it('caps a fixed amount at what is actually in the basket, and never goes below zero', function () {
    activeOffer(new OfferTerms(
        name: 'Twenty pounds off',
        type: OfferType::FixedAmount,
        target: OfferTarget::Order,
        stacking: StackingMode::Stackable,
        valueAmount: gbp(2000),
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 500]]));

    expect($entitlement->totalMinor())->toBe(500);
});

it('publishes free shipping as a real number, separately from the lines', function () {
    activeOffer(new OfferTerms(
        name: 'Free delivery',
        type: OfferType::FreeShipping,
        target: OfferTarget::Shipping,
        stacking: StackingMode::Stackable,
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]], shippingMinor: 499));

    expect($entitlement->shippingReductionMinor())->toBe(499)
        ->and($entitlement->lineReductionMinor())->toBe(0)
        ->and($entitlement->totalMinor())->toBe(499)
        ->and($entitlement->allocationByLine())->toBe([]);
});

it('refuses a free-shipping offer when there is no shipping to remove', function () {
    $offer = activeOffer(new OfferTerms(
        name: 'Free delivery',
        type: OfferType::FreeShipping,
        target: OfferTarget::Shipping,
        stacking: StackingMode::Stackable,
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]], shippingMinor: 0));

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::NothingToReduce);
});

it('evaluates only active offers, and names why each of the others did not run', function () {
    $draft = (new CreateOffer())(TENANT, percentageTerms(5000, ['name' => 'Draft']));
    $live = activeOffer(percentageTerms(1000, ['name' => 'Live']));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($entitlement->applied)->toHaveCount(1)
        ->and($entitlement->applied[0]->offerId)->toBe($live->id)
        ->and($entitlement->skipReasonFor($draft->id))->toBeNull();
});

it('honours the offer window and names which end of it was missed', function () {
    $early = activeOffer(percentageTerms(1000, ['name' => 'Starts later', 'startsAt' => at('2026-09-01')]));
    $late = activeOffer(percentageTerms(1000, ['name' => 'Already over', 'endsAt' => at('2026-07-01')]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), [], at('2026-08-13'));

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->skipReasonFor($early->id))->toBe(RefusalReason::NotYetStarted)
        ->and($entitlement->skipReasonFor($late->id))->toBe(RefusalReason::Ended);
});

it('enforces a minimum subtotal and a minimum quantity', function () {
    $spend = activeOffer(percentageTerms(1000, ['name' => 'Spend fifty', 'minimumSubtotal' => gbp(5000)]));
    $count = activeOffer(percentageTerms(1000, ['name' => 'Buy three', 'minimumQuantity' => 3]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 2, 1000]]));

    expect($entitlement->skipReasonFor($spend->id))->toBe(RefusalReason::MinimumNotMet)
        ->and($entitlement->skipReasonFor($count->id))->toBe(RefusalReason::MinimumNotMet);
});

it('refuses an offer denominated in another currency rather than converting one', function () {
    $offer = activeOffer(new OfferTerms(
        name: 'Ten euros off',
        type: OfferType::FixedAmount,
        target: OfferTarget::Order,
        stacking: StackingMode::Stackable,
        valueAmount: Money::fromMinor(1000, 'EUR'),
    ));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], currency: 'GBP'));

    expect($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::CurrencyMismatch);
});

it('refuses an offer whose total limit is spent', function () {
    $offer = activeOffer(percentageTerms(1000, ['maxRedemptions' => 2]));
    $offer->forceFill(['redemptions_used' => 2])->save();

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::Exhausted);
});

it('leaves a coded offer alone until its code is presented', function () {
    $offer = activeOffer(percentageTerms(1000));
    (new IssueCode())(TENANT, $offer->id, 'summer10');

    $without = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));
    $with = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), ['  Summer10 ']);

    expect($without->applied)->toBe([])
        ->and($without->skipReasonFor($offer->id))->toBe(RefusalReason::CodeNotPresented)
        ->and($with->totalMinor())->toBe(1000)
        ->and($with->applied[0]->code)->toBe('SUMMER10')
        ->and($with->honouredCodes)->toBe(['SUMMER10']);
});

it('applies an offer with no code automatically', function () {
    activeOffer(percentageTerms(1000));

    expect((new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]))->totalMinor())->toBe(1000);
});

it('cannot see another merchant\'s offers or codes', function () {
    $theirs = activeOffer(percentageTerms(5000), 'merchant-2');
    (new IssueCode())('merchant-2', $theirs->id, 'SECRET');

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), ['SECRET']);

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->refusedCodes)->toBe(['SECRET' => RefusalReason::UnknownCode]);
});

it('lets two merchants issue the same code, because a code is not a land grab', function () {
    $ours = activeOffer(percentageTerms(1000));
    $theirs = activeOffer(percentageTerms(5000), 'merchant-2');

    (new IssueCode())(TENANT, $ours->id, 'SUMMER10');
    (new IssueCode())('merchant-2', $theirs->id, 'SUMMER10');

    expect((new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), ['SUMMER10'])->totalMinor())->toBe(1000)
        ->and((new QuoteBasket())('merchant-2', basket([['line-1', 'p-1', 1, 10000]]), ['SUMMER10'])->totalMinor())->toBe(5000);
});

it('gives an unknown code and a real but unusable one the same shape to a shopper', function () {
    $offer = activeOffer(percentageTerms(1000, ['endsAt' => at('2026-07-01')]));
    (new IssueCode())(TENANT, $offer->id, 'EXPIRED');

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]), ['EXPIRED', 'NOSUCHCODE'], at('2026-08-13'));

    // Both refused, and the shopper-facing outcome is identical: no entitlement.
    expect($entitlement->applied)->toBe([])
        ->and($entitlement->honouredCodes)->toBe([]);

    // The merchant, and only the merchant, can tell them apart.
    expect($entitlement->refusedCodes)->toBe([
        'EXPIRED' => RefusalReason::Ended,
        'NOSUCHCODE' => RefusalReason::UnknownCode,
    ]);
});
