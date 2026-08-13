<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Promotions\Actions\ClaimRedemption;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Actions\ReleaseRedemption;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Queries\ListRedemptionsForOrder;

/**
 * The three custody properties, proved by the suite rather than asserted in a
 * document — built against absence, as wave 3 established.
 */
it('owns no catalogue: it prices a product nothing in its database has heard of', function () {
    activeOffer(percentageTerms(2500));

    $entitlement = (new QuoteBasket())(TENANT, basket([
        ['line-1', '987654321', 2, 1000],
        ['line-2', 'sku-nobody-has-registered', 1, 500],
    ]));

    expect($entitlement->totalMinor())->toBe(625)
        ->and($entitlement->allocationForLine('line-1'))->toBe(500)
        ->and($entitlement->allocationForLine('line-2'))->toBe(125);

    foreach (['promotions_offers', 'promotions_redemptions', 'promotions_redemption_lines'] as $table) {
        foreach (Schema::getForeignKeys($table) as $key) {
            expect($key['foreign_table'])->toStartWith('promotions_', "{$table} reaches outside this module");
        }
    }
});

it('owns no order: it records, queries and releases a redemption it can never resolve', function () {
    $offer = activeOffer(percentageTerms(1000));
    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 5000]]));

    $redemption = (new ClaimRedemption())(TENANT, $entitlement->applied[0], 'ord_not_a_real_order', 'GBP');

    expect((new ListRedemptionsForOrder())(TENANT, 'ord_not_a_real_order'))->toHaveCount(1)
        ->and($redemption->order_ref)->toBe('ord_not_a_real_order')
        ->and($offer->refresh()->redemptions_used)->toBe(1);

    (new ReleaseRedemption())($redemption->id, ReleaseReason::OrderCancelled, 'staff-1');

    expect($offer->refresh()->redemptions_used)->toBe(0)
        ->and($redemption->refresh()->release?->reason)->toBe(ReleaseReason::OrderCancelled);
});

it('gives promotions_redemptions no foreign key to any order, because there is no order to key to', function () {
    $foreign = array_column(Schema::getForeignKeys('promotions_redemptions'), 'foreign_table');

    expect($foreign)->not->toContain('orders')
        ->and(Schema::hasTable('orders'))->toBeFalse();

    // And nothing in the source joins to one. `order_ref` is matched as a string
    // and never resolved.
    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($source)->not->toMatch('/->join\(|->leftJoin\(|->rightJoin\(|DB::table\(/')
        ->and($source)->not->toMatch("/['\"]orders['\"]/");
});

it('owns no shopper: with both seams unbound, every offer that names no segment is unaffected', function () {
    // The whole suite runs with neither seam bound. This states it as a property
    // over the corpus rather than trusting one example: no offer here names a
    // group or a collection, so none of them can notice.
    foreach ([1000, 2500, 5000, 10000] as $rate) {
        activeOffer(percentageTerms($rate, ['name' => "Rate {$rate}", 'stacking' => StackingMode::Stackable]));
    }

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: null));

    expect($entitlement->applied)->toHaveCount(4)
        ->and($entitlement->skipped)->toBe([]);

    $withACustomer = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));

    expect($withACustomer->totalMinor())->toBe($entitlement->totalMinor());
});
