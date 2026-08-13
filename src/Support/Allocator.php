<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Support;

/**
 * Largest-remainder distribution of a minor-unit total across weighted lines.
 *
 * **This is part of the published contract, not an implementation detail.**
 * Distributing a reduction across lines leaves a remainder in minor units, and a
 * caller that re-derives where it goes differently produces a line total that
 * disagrees with the order total by a penny — forever, on every discounted
 * order. Tax reads this allocation and so does Refunds.
 *
 * The rule: floor each share, then hand the remaining pennies to the largest
 * fractional remainders, ties broken by **ascending line index** — that is,
 * basket order. The sum of the allocation equals the total exactly, for every
 * basket, every rate and every line count; tests/Unit/AllocatorTest.php proves
 * it over a generated corpus rather than over three hand-picked examples.
 */
final class Allocator
{
    /**
     * @param  array<string, int>  $weights  lineRef => weight, in the order allocation ties are broken by.
     * @return array<string, int> lineRef => amount, same order, summing to $total.
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        $sumOfWeights = array_sum($weights);
        $allocation = array_map(static fn (): int => 0, $weights);

        if ($total <= 0 || $sumOfWeights <= 0) {
            return $allocation;
        }

        // Scaled remainders, so the comparison stays in integers: the true
        // fractional part of share i is remainder_i / sumOfWeights.
        $remainders = [];
        $index = 0;

        foreach ($weights as $lineRef => $weight) {
            $scaled = $total * $weight;
            $allocation[$lineRef] = intdiv($scaled, $sumOfWeights);
            $remainders[] = ['ref' => $lineRef, 'remainder' => $scaled % $sumOfWeights, 'index' => $index++];
        }

        usort($remainders, static fn (array $a, array $b): int => $b['remainder'] <=> $a['remainder'] ?: $a['index'] <=> $b['index']);

        $pennies = $total - array_sum($allocation);

        for ($i = 0; $i < $pennies; $i++) {
            $allocation[$remainders[$i]['ref']]++;
        }

        return $allocation;
    }
}
