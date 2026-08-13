<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use Liberu\Ecommerce\Promotions\Enums\RefusalReason;

/**
 * The evaluation of a merchant's offers against **one basket at one moment**.
 *
 * Derived and perishable. It is not stored, held, reserved or carried between
 * requests: a basket that shrinks loses the entitlement it had, and a basket that
 * grows may gain one. There is no table for this and there must never be — the
 * host's session copy of an applied discount is the fault this shape exists to
 * remove.
 *
 * It publishes an **allocation**, never only a total.
 */
final readonly class Entitlement
{
    /**
     * @param  list<AppliedOffer>  $applied
     * @param  list<SkippedOffer>  $skipped  Merchant-facing.
     * @param  array<string, RefusalReason>  $refusedCodes  Presented code => reason. Merchant-facing.
     * @param  list<string>  $honouredCodes
     */
    public function __construct(
        public string $currency,
        public int $currencyExponent,
        public array $applied,
        public array $skipped = [],
        public array $refusedCodes = [],
        public array $honouredCodes = [],
    ) {}

    public function lineReductionMinor(): int
    {
        return array_sum(array_map(static fn (AppliedOffer $offer): int => $offer->lineReductionMinor(), $this->applied));
    }

    public function shippingReductionMinor(): int
    {
        return array_sum(array_map(static fn (AppliedOffer $offer): int => $offer->shippingReductionMinor, $this->applied));
    }

    public function totalMinor(): int
    {
        return $this->lineReductionMinor() + $this->shippingReductionMinor();
    }

    public function total(): Money
    {
        return Money::fromMinor($this->totalMinor(), $this->currency, $this->currencyExponent);
    }

    public function isEmpty(): bool
    {
        return $this->applied === [];
    }

    /** How much reduction landed on one line, across every applied offer. */
    public function allocationForLine(string $lineRef): int
    {
        $total = 0;

        foreach ($this->applied as $offer) {
            foreach ($offer->lines as $line) {
                if ($line->lineRef === $lineRef) {
                    $total += $line->amountMinor;
                }
            }
        }

        return $total;
    }

    /** @return array<string, int> lineRef => reduction, for every line that carried any. */
    public function allocationByLine(): array
    {
        $allocation = [];

        foreach ($this->applied as $offer) {
            foreach ($offer->lines as $line) {
                $allocation[$line->lineRef] = ($allocation[$line->lineRef] ?? 0) + $line->amountMinor;
            }
        }

        return $allocation;
    }

    public function appliedOffer(int $offerId): ?AppliedOffer
    {
        foreach ($this->applied as $offer) {
            if ($offer->offerId === $offerId) {
                return $offer;
            }
        }

        return null;
    }

    public function skipReasonFor(int $offerId): ?RefusalReason
    {
        foreach ($this->skipped as $skipped) {
            if ($skipped->offerId === $offerId) {
                return $skipped->reason;
            }
        }

        return null;
    }

    /**
     * The full shape, including the merchant-only reasons.
     *
     * A shopper-facing surface must not serialise this: `skipped` and
     * `refused_codes` together are an oracle for which codes exist.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'exponent' => $this->currencyExponent,
            'total' => $this->total()->toArray(),
            'line_reduction_minor' => $this->lineReductionMinor(),
            'shipping_reduction_minor' => $this->shippingReductionMinor(),
            'allocation_by_line' => $this->allocationByLine(),
            'applied' => array_map(static fn (AppliedOffer $offer): array => $offer->toArray(), $this->applied),
            'skipped' => array_map(static fn (SkippedOffer $offer): array => $offer->toArray(), $this->skipped),
            'refused_codes' => array_map(static fn (RefusalReason $reason): string => $reason->value, $this->refusedCodes),
            'honoured_codes' => $this->honouredCodes,
        ];
    }
}
