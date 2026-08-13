<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Support\Allocator;

it('gives the remaining pennies to the largest remainders', function () {
    // 10p over three equal lines: each floors to 3, and the leftover penny goes
    // to the first by the ascending-index tie-break.
    expect(Allocator::largestRemainder(10, ['a' => 100, 'b' => 100, 'c' => 100]))
        ->toBe(['a' => 4, 'b' => 3, 'c' => 3]);
});

it('gives the penny to the largest remainder even when that line is last', function () {
    // a and b floor to 33 with remainder 300; c floors to 33 with remainder 400.
    // Size of the remainder decides, not position — position only breaks a tie.
    expect(Allocator::largestRemainder(100, ['a' => 333, 'b' => 333, 'c' => 334]))
        ->toBe(['a' => 33, 'b' => 33, 'c' => 34]);
});

it('breaks a genuine tie by ascending line index, so basket order decides and nothing else', function () {
    $forwards = Allocator::largestRemainder(10, ['a' => 100, 'b' => 100, 'c' => 100]);
    $reordered = Allocator::largestRemainder(10, ['c' => 100, 'a' => 100, 'b' => 100]);

    expect($forwards)->toBe(['a' => 4, 'b' => 3, 'c' => 3])
        ->and($reordered)->toBe(['c' => 4, 'a' => 3, 'b' => 3]);
});

it('allocates nothing when there is nothing to allocate or nothing to allocate over', function () {
    expect(Allocator::largestRemainder(0, ['a' => 100]))->toBe(['a' => 0])
        ->and(Allocator::largestRemainder(-5, ['a' => 100]))->toBe(['a' => 0])
        ->and(Allocator::largestRemainder(50, ['a' => 0, 'b' => 0]))->toBe(['a' => 0, 'b' => 0])
        ->and(Allocator::largestRemainder(50, []))->toBe([]);
});

it('never gives a line more than its weight', function () {
    expect(Allocator::largestRemainder(300, ['a' => 100, 'b' => 200]))->toBe(['a' => 100, 'b' => 200]);
});

/**
 * The property the contract rests on. A caller that re-derives the remainder
 * differently produces a line total that disagrees with the order total by a
 * penny — forever, on every discounted order — so this is proved over a generated
 * corpus rather than over three hand-picked examples.
 */
it('sums to the total exactly, for every basket, every rate and every line count', function () {
    mt_srand(20261213);
    $checked = 0;

    foreach (range(1, 400) as $case) {
        $weights = [];

        foreach (range(1, mt_rand(1, 9)) as $line) {
            $weights['line-'.$line] = mt_rand(0, 500_00);
        }

        $subtotal = array_sum($weights);
        $total = $subtotal === 0 ? 0 : intdiv($subtotal * mt_rand(1, 10000), 10000);

        $allocation = Allocator::largestRemainder($total, $weights);

        expect(array_sum($allocation))->toBe($total)
            ->and(array_keys($allocation))->toBe(array_keys($weights));

        foreach ($allocation as $lineRef => $amount) {
            expect($amount)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($weights[$lineRef]);
        }

        $checked++;
    }

    expect($checked)->toBe(400);
});
