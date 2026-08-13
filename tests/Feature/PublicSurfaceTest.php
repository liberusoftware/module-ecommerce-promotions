<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Data\AppliedOffer;
use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\BasketLine;
use Liberu\Ecommerce\Promotions\Data\Entitlement;
use Liberu\Ecommerce\Promotions\Data\LineAllocation;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\SkippedOffer;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Exceptions\CodeAlreadyIssued;
use Liberu\Ecommerce\Promotions\Exceptions\CodeRefused;
use Liberu\Ecommerce\Promotions\Exceptions\CurrencyMismatch;
use Liberu\Ecommerce\Promotions\Exceptions\CustomerLimitReached;
use Liberu\Ecommerce\Promotions\Exceptions\InvalidOfferTerms;
use Liberu\Ecommerce\Promotions\Exceptions\OfferExhausted;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Exceptions\PromotionsException;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyRecorded;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyReleased;

/**
 * The three presentation packages are built against this surface, so it is a
 * contract. Pinning it here means a rename or a status change breaks a test in
 * this repository rather than an endpoint in somebody else's.
 */
it('publishes a stable error code and status for every failure it can raise', function (PromotionsException $error, string $code, int $status) {
    expect($error->errorCode())->toBe($code)
        ->and($error->status())->toBe($status)
        ->and($error->getMessage())->not->toBe('');
})->with([
    'code refused' => [fn () => CodeRefused::because(RefusalReason::Exhausted), 'promotions.code_refused', 422],
    'code already issued' => [fn () => CodeAlreadyIssued::inTenant('t', 'C'), 'promotions.code_already_issued', 409],
    'currency mismatch' => [fn () => CurrencyMismatch::withBasket('EUR', 'GBP'), 'promotions.currency_mismatch', 422],
    'customer limit reached' => [fn () => CustomerLimitReached::offer(1, 'cus-1'), 'promotions.customer_limit_reached', 409],
    'invalid offer terms' => [fn () => InvalidOfferTerms::because('nope'), 'promotions.invalid_offer_terms', 422],
    'offer exhausted' => [fn () => OfferExhausted::offer(1), 'promotions.offer_exhausted', 409],
    'offer not found' => [fn () => OfferNotFound::inTenant('t', 1), 'promotions.offer_not_found', 404],
    'redemption already recorded' => [fn () => RedemptionAlreadyRecorded::forOrder(1, 'ord-1'), 'promotions.redemption_already_recorded', 409],
    'redemption already released' => [fn () => RedemptionAlreadyReleased::redemption(1), 'promotions.redemption_already_released', 409],
]);

it('gives every code refusal the same message, whatever the reason behind it', function () {
    $messages = array_unique(array_map(
        static fn (RefusalReason $reason): string => CodeRefused::because($reason)->getMessage(),
        RefusalReason::cases(),
    ));

    expect($messages)->toHaveCount(1)
        ->and(reset($messages))->toBe(CodeRefused::MESSAGE);
});

it('keeps every enum value stable, because a transport serialises them', function () {
    expect(array_column(RefusalReason::cases(), 'value'))->toBe([
        'unknown_code',
        'code_not_presented',
        'not_yet_started',
        'ended',
        'exhausted',
        'customer_limit_reached',
        'customer_not_eligible',
        'eligibility_unresolvable',
        'minimum_not_met',
        'no_qualifying_lines',
        'nothing_to_reduce',
        'blocked_by_exclusive',
        'currency_mismatch',
    ]);

    expect(array_column(OfferType::cases(), 'value'))->toBe(['percentage', 'fixed_amount', 'free_shipping', 'buy_x_get_y'])
        ->and(array_column(OfferTarget::cases(), 'value'))->toBe(['order', 'product', 'collection', 'shipping'])
        ->and(array_column(OfferStatus::cases(), 'value'))->toBe(['draft', 'active', 'paused', 'ended'])
        ->and(array_column(StackingMode::cases(), 'value'))->toBe(['exclusive', 'stackable'])
        ->and(array_column(ReleaseReason::cases(), 'value'))->toBe(['order_cancelled', 'order_refunded', 'merchant_reversed', 'payment_failed'])
        ->and(array_column(OfferStatusReason::cases(), 'value'))->toBe([
            'created', 'merchant_activated', 'merchant_paused', 'merchant_resumed', 'merchant_ended', 'exhausted',
        ]);
});

it('serialises an applied offer and a skipped one into the shape a transport renders', function () {
    $applied = new AppliedOffer(
        offerId: 7,
        offerName: 'Summer twenty',
        type: OfferType::Percentage,
        stacking: StackingMode::Stackable,
        priority: 3,
        offerRevisionId: 11,
        lines: [new LineAllocation('line-1', 'sku-1', 250)],
        shippingReductionMinor: 99,
        codeId: 4,
        code: 'SUMMER20',
    );

    expect($applied->toArray())->toBe([
        'offer_id' => 7,
        'offer_name' => 'Summer twenty',
        'type' => 'percentage',
        'stacking' => 'stackable',
        'priority' => 3,
        'offer_revision_id' => 11,
        'code_id' => 4,
        'code' => 'SUMMER20',
        'lines' => [['line_ref' => 'line-1', 'product_ref' => 'sku-1', 'amount_minor' => 250]],
        'line_reduction_minor' => 250,
        'shipping_reduction_minor' => 99,
        'total_minor' => 349,
    ]);

    expect((new SkippedOffer(9, 'VIP only', RefusalReason::EligibilityUnresolvable))->toArray())->toBe([
        'offer_id' => 9,
        'offer_name' => 'VIP only',
        'reason' => 'eligibility_unresolvable',
    ]);
});

it('refuses a basket or a line that cannot mean anything', function (callable $build, string $because) {
    expect($build)->toThrow(InvalidArgumentException::class, $because);
})->with([
    'no line reference' => [fn () => new BasketLine('', 'p', 1, 100), 'needs a reference'],
    'zero quantity' => [fn () => new BasketLine('l', 'p', 0, 100), 'quantity'],
    'negative unit amount' => [fn () => new BasketLine('l', 'p', 1, -1), 'negative unit amount'],
    'bad currency' => [fn () => new Basket('POUNDS', []), 'currency code'],
    'negative shipping' => [fn () => new Basket('GBP', [], -1), 'negative shipping'],
    'duplicate line reference' => [
        fn () => new Basket('GBP', [new BasketLine('l', 'p', 1, 1), new BasketLine('l', 'q', 1, 1)]),
        'appears twice',
    ],
]);

it('reports a basket\'s totals and finds a line by reference', function () {
    $basket = basket([['line-1', 'p-1', 2, 500], ['line-2', 'p-2', 1, 300]], shippingMinor: 99);

    expect($basket->subtotalMinor())->toBe(1300)
        ->and($basket->quantity())->toBe(3)
        ->and($basket->subtotalsByLine())->toBe(['line-1' => 1000, 'line-2' => 300])
        ->and($basket->line('line-2')?->productRef)->toBe('p-2')
        ->and($basket->line('nope'))->toBeNull();
});

it('reports an empty entitlement as empty and totals it as zero money', function () {
    $entitlement = new Entitlement('GBP', 2, []);

    expect($entitlement->isEmpty())->toBeTrue()
        ->and($entitlement->totalMinor())->toBe(0)
        ->and($entitlement->total())->toEqual(Money::fromMinor(0, 'GBP'))
        ->and($entitlement->appliedOffer(1))->toBeNull()
        ->and($entitlement->skipReasonFor(1))->toBeNull()
        ->and($entitlement->allocationForLine('line-1'))->toBe(0);
});
