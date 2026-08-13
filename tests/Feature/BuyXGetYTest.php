<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;

function threeForTwo(int $basisPoints = 10000): OfferTerms
{
    return new OfferTerms(
        name: 'Three for two',
        type: OfferType::BuyXGetY,
        target: OfferTarget::Product,
        stacking: StackingMode::Stackable,
        valueBasisPoints: $basisPoints,
        productRefs: ['book'],
        buyQuantity: 2,
        getQuantity: 1,
    );
}

it('discounts the cheapest qualifying units, whatever order the basket arrives in', function () {
    activeOffer(threeForTwo());

    $expensiveFirst = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'book', 1, 3000],
        ['line-2', 'book', 1, 2000],
        ['line-3', 'book', 1, 1000],
    ]));

    $cheapestFirst = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'book', 1, 1000],
        ['line-2', 'book', 1, 2000],
        ['line-3', 'book', 1, 3000],
    ]));

    // The £10 book is free either way. The host discounts whichever line the
    // caller happened to put first, which lets a shopper reorder their basket for
    // a better price.
    expect($expensiveFirst->totalMinor())->toBe(1000)
        ->and($expensiveFirst->allocationForLine('line-3'))->toBe(1000)
        ->and($cheapestFirst->totalMinor())->toBe(1000)
        ->and($cheapestFirst->allocationForLine('line-1'))->toBe(1000);
});

it('counts a group as buy plus get, so three-for-two means paying for two of every three', function () {
    $offer = activeOffer(threeForTwo());

    // Six units at £5 → two groups of three → two units free.
    expect((new QuoteBasket())(TENANT, basket([['line-1', 'book', 6, 500]]))->totalMinor())->toBe(1000);

    // Five units are one full group and two spare, so exactly one is free.
    expect((new QuoteBasket())(TENANT, basket([['line-1', 'book', 5, 500]]))->totalMinor())->toBe(500);

    // Two units are not a group at all. The host hands out a free one here, because
    // it computes sets as quantity / buyQuantity and never requires the group to
    // be bought.
    $short = (new QuoteBasket())(TENANT, basket([['line-1', 'book', 2, 500]]));

    expect($short->totalMinor())->toBe(0)
        ->and($short->skipReasonFor($offer->id))->toBe(RefusalReason::NothingToReduce);
});

it('reads its rate as basis points, so a half-price third item is half price', function () {
    activeOffer(threeForTwo(5000));

    // Three £10 books: the cheapest unit gets 50% off, not 100%.
    expect((new QuoteBasket())(TENANT, basket([['line-1', 'book', 3, 1000]]))->totalMinor())->toBe(500);
});

it('ignores units the offer does not target', function () {
    $offer = activeOffer(threeForTwo());

    $entitlement = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'book', 2, 1000],
        ['line-2', 'kettle', 4, 1000],
    ]));

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::NothingToReduce);
});

it('attributes every penny to the line the unit came off', function () {
    activeOffer(threeForTwo());

    $entitlement = (new QuoteBasket())(TENANT, basket([
        ['line-1', 'book', 4, 900],
        ['line-2', 'book', 2, 100],
    ]));

    // Six units, two free, and the two cheapest are both on line-2.
    expect($entitlement->totalMinor())->toBe(200)
        ->and($entitlement->allocationForLine('line-2'))->toBe(200)
        ->and($entitlement->allocationForLine('line-1'))->toBe(0)
        ->and(array_sum($entitlement->allocationByLine()))->toBe($entitlement->totalMinor());
});
