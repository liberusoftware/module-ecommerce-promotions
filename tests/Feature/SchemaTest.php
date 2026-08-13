<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

const PROMOTIONS_TABLES = [
    'promotions_offers',
    'promotions_codes',
    'promotions_offer_revisions',
    'promotions_offer_status_decisions',
    'promotions_redemptions',
    'promotions_redemption_lines',
    'promotions_redemption_releases',
];

it('creates every table this module owns, and nothing outside its prefix', function () {
    foreach (PROMOTIONS_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing {$table}");
    }

    // Nothing this package invents may take a bare name: a table that existed in
    // the host keeps one, and none of these did.
    foreach (['coupons', 'discounts', 'orders', 'products'] as $hostTable) {
        expect(Schema::hasTable($hostTable))->toBeFalse("This module created {$hostTable}, which belongs to somebody else");
    }
});

it('holds every money column as an integer, never a decimal', function () {
    $money = [];

    foreach (PROMOTIONS_TABLES as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (! str_ends_with((string) $column['name'], '_minor')) {
                continue;
            }

            $money[] = $table.'.'.$column['name'];

            expect($column['type_name'])
                ->toBeIn(['integer', 'bigint', 'int', 'int8'], "{$table}.{$column['name']} is {$column['type_name']}");
        }
    }

    // Named rather than counted, so a money column added without an integer type
    // fails here instead of quietly widening a loop that asserts nothing.
    expect($money)->toBe([
        'promotions_offers.value_minor',
        'promotions_offers.minimum_subtotal_minor',
        'promotions_redemptions.line_reduction_minor',
        'promotions_redemptions.shipping_reduction_minor',
        'promotions_redemption_lines.amount_minor',
    ]);
});

it('holds every rate as basis points in an integer column', function () {
    $column = collect(Schema::getColumns('promotions_offers'))->firstWhere('name', 'value_basis_points');

    expect($column['type_name'])->toBeIn(['integer', 'bigint', 'int']);
});

it('keeps occurred_at distinct from created_at on every table that records an act', function (string $table) {
    expect(Schema::hasColumn($table, 'occurred_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'updated_at'))->toBeFalse('An append-only record is never updated');
})->with([
    ['promotions_offer_revisions'],
    ['promotions_offer_status_decisions'],
    ['promotions_redemptions'],
    ['promotions_redemption_releases'],
]);

/**
 * Declared keys, not enforced ones. SQLite honours foreign keys only with the
 * pragma on, and a pragma inside RefreshDatabase's transaction is a no-op — so
 * asserting behaviour here would assert nothing.
 */
it('declares the unique indexes that carry the constraints, rather than guarding in PHP', function (string $table, array $columns) {
    $unique = array_values(array_map(
        static fn (array $index): array => $index['columns'],
        array_filter(Schema::getIndexes($table), static fn (array $index): bool => (bool) $index['unique']),
    ));

    expect($unique)->toContainEqual($columns);
})->with([
    ['promotions_codes', ['tenant_id', 'code']],
    ['promotions_offer_revisions', ['offer_id', 'revision_number']],
    ['promotions_redemptions', ['tenant_id', 'offer_id', 'order_ref']],
    ['promotions_redemptions', ['offer_id', 'customer_ref', 'customer_sequence']],
    ['promotions_redemption_lines', ['redemption_id', 'line_ref']],
    ['promotions_redemption_releases', ['redemption_id']],
]);

it('gives the offer a covering index for the evaluation query', function () {
    $names = array_column(Schema::getIndexes('promotions_offers'), 'name');

    expect($names)->toContain('promotions_offers_evaluation_index');
});
