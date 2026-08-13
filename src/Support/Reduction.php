<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Support;

use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\BasketLine;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferType;

/**
 * What one offer takes off, given the amounts still left to take it off.
 *
 * Everything here is arithmetic on integers over amounts supplied by the caller.
 * This module never sees a price it did not receive as an argument.
 *
 * @phpstan-type Residuals array<string, int>
 */
final class Reduction
{
    /**
     * @param  list<string>  $qualifyingLineRefs  In basket order.
     * @param  array<string, int>  $residualByLine  lineRef => amount still reducible, in basket order.
     * @return array{lines: array<string, int>, shipping: int}
     */
    public static function compute(
        OfferTerms $terms,
        Basket $basket,
        array $qualifyingLineRefs,
        array $residualByLine,
        int $residualShipping,
    ): array {
        $weights = [];

        foreach ($qualifyingLineRefs as $lineRef) {
            $weights[$lineRef] = $residualByLine[$lineRef] ?? 0;
        }

        return match ($terms->type) {
            OfferType::FreeShipping => ['lines' => [], 'shipping' => $residualShipping],
            OfferType::Percentage => [
                'lines' => Allocator::largestRemainder(
                    intdiv(array_sum($weights) * (int) $terms->valueBasisPoints, 10000),
                    $weights,
                ),
                'shipping' => 0,
            ],
            OfferType::FixedAmount => [
                'lines' => Allocator::largestRemainder(
                    min((int) $terms->valueAmount?->minor, array_sum($weights)),
                    $weights,
                ),
                'shipping' => 0,
            ],
            OfferType::BuyXGetY => [
                'lines' => self::buyXGetY($terms, $basket, $qualifyingLineRefs, $weights),
                'shipping' => 0,
            ],
        };
    }

    /**
     * Buy X get Y, discounting the **cheapest** qualifying units.
     *
     * That is the conventional rule and the only one that does not reward a
     * shopper for reordering their basket. The host iterates eligible lines in
     * array order, so over a mixed-price set "buy two get one free" discounts
     * whichever line the caller happened to put first.
     *
     * A group is `buy + get` units, so three-for-two means paying for two of every
     * three. The host computes `sets = quantity / buyQuantity` instead, which
     * hands out free units without requiring the group to be bought at all.
     *
     * The rate is basis points and is applied as one: 10000 makes the unit free.
     * The host applies `value / 100` whatever the type, under a comment saying the
     * value is a "percentage or fixed amount".
     *
     * No allocator is needed — every penny is attributed to the unit it came off,
     * so the per-line sum is the total by construction.
     *
     * @param  list<string>  $qualifyingLineRefs
     * @param  array<string, int>  $weights  lineRef => residual, the cap per line.
     * @return array<string, int>
     */
    private static function buyXGetY(OfferTerms $terms, Basket $basket, array $qualifyingLineRefs, array $weights): array
    {
        $allocation = array_map(static fn (): int => 0, $weights);
        $groupSize = (int) $terms->buyQuantity + (int) $terms->getQuantity;

        $units = [];
        $index = 0;

        foreach ($qualifyingLineRefs as $lineRef) {
            $line = $basket->line($lineRef);

            if (! $line instanceof BasketLine) {
                continue;
            }

            for ($i = 0; $i < $line->quantity; $i++) {
                $units[] = ['ref' => $lineRef, 'amount' => $line->unitAmountMinor, 'index' => $index];
            }

            $index++;
        }

        $freeUnits = intdiv(count($units), $groupSize) * (int) $terms->getQuantity;

        if ($freeUnits < 1) {
            return $allocation;
        }

        // Cheapest first; ties by basket order, so the result does not depend on
        // sort stability.
        usort($units, static fn (array $a, array $b): int => $a['amount'] <=> $b['amount'] ?: $a['index'] <=> $b['index']);

        foreach (array_slice($units, 0, $freeUnits) as $unit) {
            $take = intdiv($unit['amount'] * (int) $terms->valueBasisPoints, 10000);
            $allocation[$unit['ref']] = min($allocation[$unit['ref']] + $take, $weights[$unit['ref']]);
        }

        return $allocation;
    }
}
