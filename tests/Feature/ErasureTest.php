<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Actions\RedactCustomerFromRedemptions;
use Liberu\Ecommerce\Promotions\Actions\ReleaseRedemption;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Events\CustomerRedactedFromRedemptions;
use Liberu\Ecommerce\Promotions\Queries\RecomputeRedemptionsUsed;

it('redacts the shopper and keeps the shape: the redemption, its lines and its release all survive', function () {
    Event::fake([CustomerRedactedFromRedemptions::class]);
    $offer = activeOffer(percentageTerms(2500, ['maxRedemptionsPerCustomer' => 3]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 2, 5000]], customerRef: 'cus-1'));
    $kept = (new ClaimRedemption())(TENANT, $entitlement->applied[0], 'ord-1', 'GBP', customerRef: 'cus-1');

    $second = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]], customerRef: 'cus-1'));
    $released = (new ClaimRedemption())(TENANT, $second->applied[0], 'ord-2', 'GBP', customerRef: 'cus-1');
    (new ReleaseRedemption())($released->id, ReleaseReason::OrderRefunded, 'staff-1');

    $before = $offer->refresh()->redemptions_used;

    expect((new RedactCustomerFromRedemptions())(TENANT, 'cus-1'))->toBe(2);

    expect($kept->refresh()->customer_ref)->toBeNull()
        ->and($kept->customer_sequence)->toBeNull()
        ->and($kept->line_reduction_minor)->toBe(2500)
        ->and($kept->lines)->toHaveCount(1)
        ->and($kept->order_ref)->toBe('ord-1')
        ->and($released->refresh()->customer_ref)->toBeNull()
        ->and($released->release?->reason)->toBe(ReleaseReason::OrderRefunded);

    // A merchant's usage limits and reconciliation do not change because a
    // shopper exercised a right.
    expect($offer->refresh()->redemptions_used)->toBe($before)
        ->and((new RecomputeRedemptionsUsed())->agrees($offer))->toBeTrue();

    Event::assertDispatched(CustomerRedactedFromRedemptions::class);
});

it('touches only the merchant that asked, and only the shopper that asked', function () {
    activeOffer(percentageTerms(1000));
    $theirs = activeOffer(percentageTerms(1000), 'merchant-2');

    $ours = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));
    (new ClaimRedemption())(TENANT, $ours->applied[0], 'ord-1', 'GBP', customerRef: 'cus-1');

    $otherShopper = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-2'));
    $keepThis = (new ClaimRedemption())(TENANT, $otherShopper->applied[0], 'ord-2', 'GBP', customerRef: 'cus-2');

    $otherMerchant = (new QuoteBasket())('merchant-2', basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));
    $keepThatToo = (new ClaimRedemption())('merchant-2', $otherMerchant->applied[0], 'ord-3', 'GBP', customerRef: 'cus-1');

    expect((new RedactCustomerFromRedemptions())(TENANT, 'cus-1'))->toBe(1)
        ->and($keepThis->refresh()->customer_ref)->toBe('cus-2')
        ->and($keepThatToo->refresh()->customer_ref)->toBe('cus-1')
        ->and($theirs->refresh()->redemptions_used)->toBe(1);
});

it('reports nothing redacted when the shopper has no history here', function () {
    expect((new RedactCustomerFromRedemptions())(TENANT, 'nobody'))->toBe(0);
});
