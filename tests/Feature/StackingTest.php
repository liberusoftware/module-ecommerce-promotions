<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;

it('stacks two stackable offers, each taking its share of what is left', function () {
    activeOffer(percentageTerms(1000, ['name' => 'Ten first', 'priority' => 1, 'stacking' => StackingMode::Stackable]));
    activeOffer(percentageTerms(1000, ['name' => 'Ten second', 'priority' => 2, 'stacking' => StackingMode::Stackable]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    // 10% of 10000 = 1000, then 10% of the remaining 9000 = 900. Compounding on
    // the residual is what keeps a stack from ever exceeding the basket.
    expect($entitlement->applied)->toHaveCount(2)
        ->and($entitlement->totalMinor())->toBe(1900);
});

it('never lets a stack take more than the basket holds', function () {
    foreach (range(1, 6) as $n) {
        activeOffer(percentageTerms(8000, ['name' => "Eighty {$n}", 'priority' => $n, 'stacking' => StackingMode::Stackable]));
    }

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 1000]]));

    expect($entitlement->totalMinor())->toBeLessThanOrEqual(1000)
        ->and($entitlement->totalMinor())->toBeGreaterThan(0);
});

it('lets one exclusive offer win outright and refuses every other by name', function () {
    $stackable = activeOffer(percentageTerms(1000, ['name' => 'Everyday ten', 'priority' => 2, 'stacking' => StackingMode::Stackable]));
    $exclusive = activeOffer(percentageTerms(5000, ['name' => 'Half price', 'priority' => 1, 'stacking' => StackingMode::Exclusive]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($entitlement->applied)->toHaveCount(1)
        ->and($entitlement->applied[0]->offerId)->toBe($exclusive->id)
        ->and($entitlement->totalMinor())->toBe(5000)
        ->and($entitlement->skipReasonFor($stackable->id))->toBe(RefusalReason::BlockedByExclusive);
});

it('computes an exclusive winner against the untouched basket, not against offers it displaced', function () {
    // The stackable one sorts first and would otherwise have eaten into the
    // residual before the exclusive one was ever discarded.
    activeOffer(percentageTerms(9000, ['name' => 'Ninety off', 'priority' => 1, 'stacking' => StackingMode::Stackable]));
    activeOffer(percentageTerms(5000, ['name' => 'Half price', 'priority' => 2, 'stacking' => StackingMode::Exclusive]));

    expect((new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]))->totalMinor())->toBe(5000);
});

it('picks the first exclusive offer in evaluation order when two of them apply', function () {
    $first = activeOffer(percentageTerms(1000, ['name' => 'Modest', 'priority' => 1, 'stacking' => StackingMode::Exclusive]));
    $second = activeOffer(percentageTerms(9000, ['name' => 'Generous', 'priority' => 2, 'stacking' => StackingMode::Exclusive]));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    // Priority is the merchant's stated order, and it is obeyed even when the
    // result is smaller. A rule that quietly picked the largest would be a
    // different rule than the one the merchant wrote down.
    expect($entitlement->applied[0]->offerId)->toBe($first->id)
        ->and($entitlement->skipReasonFor($second->id))->toBe(RefusalReason::BlockedByExclusive);
});

it('breaks a priority tie by ascending offer id, deterministically on every run', function () {
    $first = activeOffer(percentageTerms(1000, ['name' => 'A', 'priority' => 5, 'stacking' => StackingMode::Exclusive]));
    activeOffer(percentageTerms(9000, ['name' => 'B', 'priority' => 5, 'stacking' => StackingMode::Exclusive]));

    foreach (range(1, 5) as $run) {
        $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

        expect($entitlement->applied[0]->offerId)->toBe($first->id, "Run {$run} disagreed")
            ->and($entitlement->totalMinor())->toBe(1000);
    }
});

it('produces the same allocation on every run for a stack over many lines', function () {
    activeOffer(percentageTerms(3333, ['name' => 'A third', 'priority' => 1, 'stacking' => StackingMode::Stackable]));
    activeOffer(percentageTerms(1111, ['name' => 'A ninth', 'priority' => 2, 'stacking' => StackingMode::Stackable]));

    $lines = [['line-1', 'p-1', 3, 333], ['line-2', 'p-2', 1, 7], ['line-3', 'p-3', 2, 1999]];
    $first = (new QuoteBasket())(TENANT, basket($lines))->toArray();

    foreach (range(1, 5) as $run) {
        expect((new QuoteBasket())(TENANT, basket($lines))->toArray())->toBe($first, "Run {$run} disagreed");
    }
});
