<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesCustomerEligibility;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesProductGrouping;
use Liberu\Ecommerce\Promotions\Data\AppliedOffer;
use Liberu\Ecommerce\Promotions\Data\Basket;
use Liberu\Ecommerce\Promotions\Data\Entitlement;
use Liberu\Ecommerce\Promotions\Data\LineAllocation;
use Liberu\Ecommerce\Promotions\Data\SkippedOffer;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Support\OfferGate;
use Liberu\Ecommerce\Promotions\Support\Reduction;

/**
 * Evaluate a merchant's offers against **one basket at one moment**.
 *
 * Writes nothing. Stores nothing. Reserves nothing. The result is perishable and
 * must be recomputed on every basket change — that is the host's checkout
 * revalidation, which is right, without the host's stale session copy, which is
 * the same fault left in place beside it.
 *
 * Both seams are optional and unbound by default. An offer that needs an absent
 * one is refused by name and every other offer evaluates normally; see
 * {@see OfferGate}.
 */
final class QuoteBasket
{
    private OfferGate $gate;

    public function __construct(
        ?ResolvesCustomerEligibility $customers = null,
        ?ResolvesProductGrouping $grouping = null,
    ) {
        $this->gate = new OfferGate($customers, $grouping);
    }

    /** @param list<string> $codes Codes the shopper presented. */
    public function __invoke(string $tenantId, Basket $basket, array $codes = [], ?CarbonImmutable $at = null): Entitlement
    {
        $at ??= CarbonImmutable::now();
        $presented = array_values(array_unique(array_map(Code::normalise(...), $codes)));

        $offers = Offer::query()
            ->where('tenant_id', $tenantId)
            ->where('status', OfferStatus::Active)
            // Deterministic and tested: ascending priority, ties by ascending id.
            // Two offers that both apply must produce the same result on every
            // run, or a merchant's revenue depends on row order.
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        /** @var array<int, Code> $codeByOffer */
        $codeByOffer = [];
        /** @var array<string, int> $offerByCode */
        $offerByCode = [];

        if ($presented !== []) {
            foreach (Code::query()->where('tenant_id', $tenantId)->whereIn('code', $presented)->get() as $code) {
                $codeByOffer[$code->offer_id] ??= $code;
                $offerByCode[$code->code] = $code->offer_id;
            }
        }

        $codeBearing = array_flip(
            Code::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('offer_id', $offers->modelKeys())
                ->distinct()
                ->pluck('offer_id')
                ->all()
        );

        // Pass one: who qualifies, judged against the pristine basket. Doing this
        // before any stacking decision is what keeps the exclusive rule honest —
        // an offer's amount must not depend on offers that are about to be
        // discarded.
        $reasons = [];
        $qualifying = [];

        foreach ($offers as $offer) {
            $verdict = $this->gate->admit(
                $offer,
                $basket,
                $at,
                isset($codeBearing[$offer->id]),
                isset($codeByOffer[$offer->id]),
            );

            if ($verdict instanceof RefusalReason) {
                $reasons[$offer->id] = $verdict;

                continue;
            }

            $dry = Reduction::compute($offer->terms(), $basket, $verdict, $basket->subtotalsByLine(), $basket->shippingMinor);

            if (array_sum($dry['lines']) + $dry['shipping'] < 1) {
                $reasons[$offer->id] = RefusalReason::NothingToReduce;

                continue;
            }

            $qualifying[$offer->id] = $verdict;
        }

        $winners = $this->selectWinners($offers, $qualifying, $reasons);

        // Pass two: amounts, over what each earlier winner has already taken. A
        // reduction can zero an order and must never take it below zero.
        $residualByLine = $basket->subtotalsByLine();
        $residualShipping = $basket->shippingMinor;
        $applied = [];

        foreach ($offers as $offer) {
            if (! isset($winners[$offer->id])) {
                continue;
            }

            $result = Reduction::compute($offer->terms(), $basket, $winners[$offer->id], $residualByLine, $residualShipping);
            $lines = [];

            foreach ($result['lines'] as $lineRef => $amount) {
                if ($amount < 1) {
                    continue;
                }

                $residualByLine[$lineRef] -= $amount;
                $lines[] = new LineAllocation($lineRef, $basket->line($lineRef)?->productRef ?? '', $amount);
            }

            $residualShipping -= $result['shipping'];

            if ($lines === [] && $result['shipping'] < 1) {
                $reasons[$offer->id] = RefusalReason::NothingToReduce;

                continue;
            }

            $code = $codeByOffer[$offer->id] ?? null;

            $applied[] = new AppliedOffer(
                offerId: $offer->id,
                offerName: $offer->name,
                type: $offer->type,
                stacking: $offer->stacking,
                priority: $offer->priority,
                offerRevisionId: (int) $offer->current_revision_id,
                lines: $lines,
                shippingReductionMinor: $result['shipping'],
                codeId: $code?->id,
                code: $code?->code,
            );
        }

        return new Entitlement(
            currency: $basket->currency,
            currencyExponent: $basket->currencyExponent,
            applied: $applied,
            skipped: $this->skipped($offers, $reasons),
            refusedCodes: $this->refusals($presented, $offerByCode, $reasons, $applied),
            honouredCodes: $this->honoured($presented, $offerByCode, $applied),
        );
    }

    /**
     * Exclusive means "if it applies, nothing else may". The first qualifying
     * exclusive offer in evaluation order wins outright; everything else is
     * refused by name, so a merchant can see it happen.
     *
     * @param  Collection<int, Offer>  $offers
     * @param  array<int, list<string>>  $qualifying
     * @param  array<int, RefusalReason>  $reasons
     * @return array<int, list<string>>
     */
    private function selectWinners($offers, array $qualifying, array &$reasons): array
    {
        foreach ($offers as $offer) {
            if (! isset($qualifying[$offer->id]) || $offer->stacking !== StackingMode::Exclusive) {
                continue;
            }

            foreach (array_keys($qualifying) as $otherId) {
                if ($otherId !== $offer->id) {
                    $reasons[$otherId] = RefusalReason::BlockedByExclusive;
                }
            }

            return [$offer->id => $qualifying[$offer->id]];
        }

        return $qualifying;
    }

    /**
     * @param  Collection<int, Offer>  $offers
     * @param  array<int, RefusalReason>  $reasons
     * @return list<SkippedOffer>
     */
    private function skipped($offers, array $reasons): array
    {
        $skipped = [];

        foreach ($offers as $offer) {
            if (isset($reasons[$offer->id])) {
                $skipped[] = new SkippedOffer($offer->id, $offer->name, $reasons[$offer->id]);
            }
        }

        return $skipped;
    }

    /**
     * Why each presented code did not land — **for the merchant-facing surface
     * only**. A shopper gets the one message from `CodeRefused` whatever the
     * reason, because a per-reason answer is an oracle for which codes exist.
     *
     * @param  list<string>  $presented
     * @param  array<string, int>  $offerByCode
     * @param  array<int, RefusalReason>  $reasons
     * @param  list<AppliedOffer>  $applied
     * @return array<string, RefusalReason>
     */
    private function refusals(array $presented, array $offerByCode, array $reasons, array $applied): array
    {
        $appliedIds = array_map(static fn (AppliedOffer $offer): int => $offer->offerId, $applied);
        $refused = [];

        foreach ($presented as $code) {
            $offerId = $offerByCode[$code] ?? null;

            if ($offerId === null) {
                $refused[$code] = RefusalReason::UnknownCode;

                continue;
            }

            if (! in_array($offerId, $appliedIds, true)) {
                // An offer the quote never considered — paused, ended, draft, or
                // belonging to nobody the tenant knows — is indistinguishable
                // from an unknown code, which is the point.
                $refused[$code] = $reasons[$offerId] ?? RefusalReason::UnknownCode;
            }
        }

        return $refused;
    }

    /**
     * @param  list<string>  $presented
     * @param  array<string, int>  $offerByCode
     * @param  list<AppliedOffer>  $applied
     * @return list<string>
     */
    private function honoured(array $presented, array $offerByCode, array $applied): array
    {
        $appliedIds = array_map(static fn (AppliedOffer $offer): int => $offer->offerId, $applied);

        return array_values(array_filter(
            $presented,
            static fn (string $code): bool => isset($offerByCode[$code]) && in_array($offerByCode[$code], $appliedIds, true),
        ));
    }
}
