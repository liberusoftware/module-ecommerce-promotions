<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\CreateOffer;
use Liberu\Ecommerce\Promotions\Actions\DecideOfferStatus;
use Liberu\Ecommerce\Promotions\Actions\IssueCode;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Actions\ReviseOfferTerms;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Events\CodeIssued;
use Liberu\Ecommerce\Promotions\Events\OfferCreated;
use Liberu\Ecommerce\Promotions\Events\OfferStatusDecided;
use Liberu\Ecommerce\Promotions\Events\OfferTermsRevised;
use Liberu\Ecommerce\Promotions\Exceptions\CodeAlreadyIssued;
use Liberu\Ecommerce\Promotions\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\Promotions\Exceptions\InvalidOfferTerms;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Queries\FindOfferByCode;
use Liberu\Ecommerce\Promotions\Queries\ListOfferHistory;
use Liberu\Ecommerce\Promotions\Queries\RecomputeOfferStatus;

it('creates an offer as a draft, with its first revision and the decision that made it', function () {
    Event::fake([OfferCreated::class]);

    $offer = (new CreateOffer())(TENANT, percentageTerms(2000), 'staff-1', at('2026-08-01 09:00'));

    expect($offer->status)->toBe(OfferStatus::Draft)
        ->and($offer->revision_number)->toBe(1)
        ->and($offer->redemptions_used)->toBe(0)
        ->and($offer->current_revision_id)->not->toBeNull()
        ->and($offer->value_basis_points)->toBe(2000);

    $history = new ListOfferHistory();

    expect($history->revisions($offer->id))->toHaveCount(1)
        ->and($history->revisions($offer->id)[0]->actor_ref)->toBe('staff-1')
        ->and($history->revisions($offer->id)[0]->occurred_at->toDateTimeString())->toBe('2026-08-01 09:00:00')
        ->and($history->statusDecisions($offer->id)[0]->reason)->toBe(OfferStatusReason::Created)
        ->and($history->statusDecisions($offer->id)[0]->from_status)->toBeNull();

    Event::assertDispatched(OfferCreated::class);
});

it('reads the live terms back off the columns, not out of the archive', function () {
    $offer = activeOffer(new OfferTerms(
        name: 'Winter twenty',
        type: OfferType::Percentage,
        target: OfferTarget::Product,
        stacking: StackingMode::Exclusive,
        description: 'Twenty off the coats',
        valueBasisPoints: 2000,
        minimumSubtotal: gbp(5000),
        minimumQuantity: 2,
        productRefs: ['coat', 'scarf'],
        customerGroupRefs: ['vip'],
        priority: 7,
        startsAt: at('2026-11-01'),
        endsAt: at('2026-12-01'),
        maxRedemptions: 100,
        maxRedemptionsPerCustomer: 1,
    ));

    $terms = $offer->refresh()->terms();

    expect($terms->name)->toBe('Winter twenty')
        ->and($terms->type)->toBe(OfferType::Percentage)
        ->and($terms->target)->toBe(OfferTarget::Product)
        ->and($terms->stacking)->toBe(StackingMode::Exclusive)
        ->and($terms->valueBasisPoints)->toBe(2000)
        ->and($terms->minimumSubtotal?->minor)->toBe(5000)
        ->and($terms->minimumSubtotal?->currency)->toBe('GBP')
        ->and($terms->minimumQuantity)->toBe(2)
        ->and($terms->productRefs)->toBe(['coat', 'scarf'])
        ->and($terms->customerGroupRefs)->toBe(['vip'])
        ->and($terms->priority)->toBe(7)
        ->and($terms->maxRedemptions)->toBe(100)
        ->and($terms->maxRedemptionsPerCustomer)->toBe(1)
        ->and($terms->startsAt?->toDateString())->toBe('2026-11-01');
});

it('changes what happens next without changing what already happened', function () {
    Event::fake([OfferTermsRevised::class]);
    $offer = activeOffer(percentageTerms(1000));

    $before = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));
    $redemption = (new ClaimRedemption())(TENANT, $before->applied[0], 'ord-1', 'GBP');

    $revision = (new ReviseOfferTerms())(TENANT, $offer->id, percentageTerms(5000), 'staff-2');

    $after = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($before->totalMinor())->toBe(1000)
        ->and($after->totalMinor())->toBe(5000)
        ->and($offer->refresh()->revision_number)->toBe(2)
        ->and($offer->current_revision_id)->toBe($revision->id);

    // The redemption still names the terms it was actually evaluated under.
    expect($redemption->refresh()->offer_revision_id)->not->toBe($revision->id)
        ->and($redemption->revision->revision_number)->toBe(1)
        ->and($redemption->revision->terms['value_basis_points'])->toBe(1000)
        ->and($redemption->line_reduction_minor)->toBe(1000);

    Event::assertDispatched(OfferTermsRevised::class);
});

it('keeps every revision, in order, with its actor', function () {
    $offer = activeOffer(percentageTerms(1000));

    (new ReviseOfferTerms())(TENANT, $offer->id, percentageTerms(2000), 'staff-2');
    (new ReviseOfferTerms())(TENANT, $offer->id, percentageTerms(3000), 'staff-3');

    $revisions = (new ListOfferHistory())->revisions($offer->id);

    expect($revisions)->toHaveCount(3)
        ->and($revisions->pluck('revision_number')->all())->toBe([1, 2, 3])
        ->and($revisions->pluck('actor_ref')->all())->toBe(['staff-1', 'staff-2', 'staff-3'])
        ->and(array_column($revisions->pluck('terms')->all(), 'value_basis_points'))->toBe([1000, 2000, 3000]);
});

it('records who paused an offer, when, and why — and stops evaluating it', function () {
    Event::fake([OfferStatusDecided::class]);
    $offer = activeOffer(percentageTerms(1000));

    expect((new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]))->totalMinor())->toBe(1000);

    (new DecideOfferStatus())(
        TENANT,
        $offer->id,
        OfferStatus::Paused,
        OfferStatusReason::MerchantPaused,
        'staff-9',
        'Margin review',
        at('2026-11-27 09:00'),
    );

    $decision = (new ListOfferHistory())->statusDecisions($offer->id)->last();

    expect($offer->refresh()->status)->toBe(OfferStatus::Paused)
        ->and((new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]))->applied)->toBe([])
        ->and($decision->from_status)->toBe(OfferStatus::Active)
        ->and($decision->to_status)->toBe(OfferStatus::Paused)
        ->and($decision->reason)->toBe(OfferStatusReason::MerchantPaused)
        ->and($decision->actor_ref)->toBe('staff-9')
        ->and($decision->note)->toBe('Margin review')
        ->and($decision->occurred_at->toDateTimeString())->toBe('2026-11-27 09:00:00');

    Event::assertDispatched(OfferStatusDecided::class);
});

it('keeps the status column in step with the decision log', function () {
    $offer = activeOffer(percentageTerms(1000));
    $recompute = new RecomputeOfferStatus();

    expect($recompute->agrees($offer->refresh()))->toBeTrue();

    foreach ([
        [OfferStatus::Paused, OfferStatusReason::MerchantPaused],
        [OfferStatus::Active, OfferStatusReason::MerchantResumed],
        [OfferStatus::Ended, OfferStatusReason::MerchantEnded],
    ] as [$status, $reason]) {
        (new DecideOfferStatus())(TENANT, $offer->id, $status, $reason, 'staff-1');

        expect($recompute->agrees($offer->refresh()))->toBeTrue()
            ->and($recompute($offer->id))->toBe($status);
    }

    expect((new ListOfferHistory())->statusDecisions($offer->id))->toHaveCount(5);
});

it('issues codes, normalises them, and refuses a duplicate within one merchant', function () {
    Event::fake([CodeIssued::class]);
    $offer = activeOffer(percentageTerms(1000));

    $code = (new IssueCode())(TENANT, $offer->id, '  summer10 ');

    expect($code->code)->toBe('SUMMER10')
        ->and(fn () => (new IssueCode())(TENANT, $offer->id, 'SUMMER10'))->toThrow(CodeAlreadyIssued::class);

    // Many codes may reach one offer.
    (new IssueCode())(TENANT, $offer->id, 'PARTNER10');

    expect($offer->refresh()->codes)->toHaveCount(2)
        ->and((new FindOfferByCode())(TENANT, 'summer10')?->id)->toBe($offer->id)
        ->and((new FindOfferByCode())(TENANT, 'NOPE'))->toBeNull()
        ->and((new FindOfferByCode())('merchant-2', 'SUMMER10'))->toBeNull();

    Event::assertDispatched(CodeIssued::class);
});

it('refuses to touch an offer belonging to another merchant', function () {
    $offer = activeOffer(percentageTerms(1000));

    expect(fn () => (new ReviseOfferTerms())('merchant-2', $offer->id, percentageTerms(5000)))->toThrow(OfferNotFound::class)
        ->and(fn () => (new DecideOfferStatus())('merchant-2', $offer->id, OfferStatus::Paused, OfferStatusReason::MerchantPaused))->toThrow(OfferNotFound::class)
        ->and(fn () => (new IssueCode())('merchant-2', $offer->id, 'X'))->toThrow(OfferNotFound::class);

    $notFound = OfferNotFound::inTenant('merchant-2', $offer->id);

    expect($notFound->errorCode())->toBe('promotions.offer_not_found')
        ->and($notFound->status())->toBe(404);
});

it('refuses terms a merchant cannot have meant', function (callable $build, string $because) {
    expect($build)->toThrow(InvalidOfferTerms::class, $because);
})->with([
    'no name' => [fn () => percentageTerms(1000, ['name' => '  ']), 'needs a name'],
    'rate out of range' => [fn () => percentageTerms(10001), 'basis points'],
    'zero rate' => [fn () => percentageTerms(0), 'basis points'],
    'ends before it starts' => [fn () => percentageTerms(1000, ['startsAt' => at('2026-12-01'), 'endsAt' => at('2026-11-01')]), 'cannot end before'],
    'product target with no products' => [fn () => percentageTerms(1000, ['target' => OfferTarget::Product]), 'must name at least one'],
    'collection target with no collections' => [fn () => percentageTerms(1000, ['target' => OfferTarget::Collection]), 'must name at least one'],
    'shipping target that is not free shipping' => [fn () => percentageTerms(1000, ['target' => OfferTarget::Shipping]), 'Only a free-shipping offer'],
    'limit below one' => [fn () => percentageTerms(1000, ['maxRedemptions' => 0]), 'not a limit'],
    'per-customer limit below one' => [fn () => percentageTerms(1000, ['maxRedemptionsPerCustomer' => 0]), 'not a limit'],
    'minimum quantity below one' => [fn () => percentageTerms(1000, ['minimumQuantity' => 0]), 'not a minimum'],
    'fixed amount with no amount' => [fn () => new OfferTerms('X', OfferType::FixedAmount, OfferTarget::Order, StackingMode::Stackable), 'positive amount'],
    'fixed amount carrying a rate' => [fn () => new OfferTerms('X', OfferType::FixedAmount, OfferTarget::Order, StackingMode::Stackable, valueBasisPoints: 1000, valueAmount: gbp(100)), 'not a rate'],
    'percentage carrying an amount' => [fn () => new OfferTerms('X', OfferType::Percentage, OfferTarget::Order, StackingMode::Stackable, valueBasisPoints: 1000, valueAmount: gbp(100)), 'not an amount'],
    'free shipping aimed elsewhere' => [fn () => new OfferTerms('X', OfferType::FreeShipping, OfferTarget::Order, StackingMode::Stackable), 'targets shipping'],
    'free shipping with a value' => [fn () => new OfferTerms('X', OfferType::FreeShipping, OfferTarget::Shipping, StackingMode::Stackable, valueBasisPoints: 5000), 'carries no value'],
    'buy x get y with no quantities' => [fn () => new OfferTerms('X', OfferType::BuyXGetY, OfferTarget::Order, StackingMode::Stackable, valueBasisPoints: 10000), 'buy and get quantity'],
    'quantities on the wrong type' => [fn () => new OfferTerms('X', OfferType::Percentage, OfferTarget::Order, StackingMode::Stackable, valueBasisPoints: 1000, buyQuantity: 2, getQuantity: 1), 'no buy or get quantity'],
]);

it('carries the invalid-terms error code a transport should render', function () {
    $invalid = InvalidOfferTerms::because('nope');

    expect($invalid->errorCode())->toBe('promotions.invalid_offer_terms')
        ->and($invalid->status())->toBe(422);
});

it('refuses an offer that mixes two currencies, as a currency fault rather than a terms fault', function () {
    expect(fn () => new OfferTerms(
        'X',
        OfferType::FixedAmount,
        OfferTarget::Order,
        StackingMode::Stackable,
        valueAmount: gbp(100),
        minimumSubtotal: Money::fromMinor(100, 'EUR'),
    ))->toThrow(CurrencyMismatch::class, 'Cannot combine');
});
