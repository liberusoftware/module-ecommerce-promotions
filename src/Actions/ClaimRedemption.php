<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Data\AppliedOffer;
use Liberu\Ecommerce\Promotions\Data\LineAllocation;
use Liberu\Ecommerce\Promotions\Events\RedemptionRecorded;
use Liberu\Ecommerce\Promotions\Exceptions\CustomerLimitReached;
use Liberu\Ecommerce\Promotions\Exceptions\OfferExhausted;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Exceptions\RedemptionAlreadyRecorded;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionLine;

/**
 * Record that an offer was spent on an order, and claim its use.
 *
 * The total limit is claimed by a **conditional update**:
 *
 *     UPDATE promotions_offers SET redemptions_used = redemptions_used + 1
 *      WHERE id = ? AND (max_redemptions IS NULL OR redemptions_used < max_redemptions)
 *
 * **Zero affected rows means exhausted.** That is race-free without a lock, and
 * it is the direct answer to a host that counts rows in `orders` to decide
 * whether a coupon is spent and takes `lockForUpdate()` on the wrong row to
 * serialise it.
 *
 * The per-order and per-customer limits are enforced by **unique indexes**, never
 * by a guard: a check-then-act in PHP is not a constraint, and a model hook does
 * not fire for `query()->update()`.
 *
 * No idempotency key. The per-order unique index is on (tenant, offer, order
 * reference), every component of which is server-supplied — a client-held key
 * would be strictly weaker than the constraint already there.
 */
final class ClaimRedemption
{
    public function __invoke(
        string $tenantId,
        AppliedOffer $applied,
        string $orderRef,
        string $currency,
        int $currencyExponent = 2,
        ?string $customerRef = null,
        ?CarbonImmutable $occurredAt = null,
    ): Redemption {
        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $applied, $orderRef, $currency, $currencyExponent, $customerRef, $occurredAt): Redemption {
            $offer = Offer::query()->where('tenant_id', $tenantId)->find($applied->offerId);

            if (! $offer instanceof Offer) {
                throw OfferNotFound::inTenant($tenantId, $applied->offerId);
            }

            $sequence = $this->nextCustomerSlot($offer, $customerRef);

            // A pre-flight for the message only. The unique index is what
            // enforces this; asking first just means the ordinary duplicate gets
            // named accurately instead of being guessed at from a race.
            if (Redemption::query()->where('tenant_id', $tenantId)->where('offer_id', $offer->id)->where('order_ref', $orderRef)->exists()) {
                throw RedemptionAlreadyRecorded::forOrder($offer->id, $orderRef);
            }

            try {
                $redemption = Redemption::query()->create([
                    'tenant_id' => $tenantId,
                    'offer_id' => $offer->id,
                    'offer_revision_id' => $applied->offerRevisionId,
                    'code_id' => $applied->codeId,
                    'order_ref' => $orderRef,
                    'customer_ref' => $customerRef,
                    'customer_sequence' => $sequence,
                    'currency' => strtoupper($currency),
                    'currency_exponent' => $currencyExponent,
                    'line_reduction_minor' => $applied->lineReductionMinor(),
                    'shipping_reduction_minor' => $applied->shippingReductionMinor,
                    'occurred_at' => $occurredAt,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A lost race: another request took the same per-customer slot,
                // or put the same offer on the same order, between the pre-flight
                // and here. Both fail closed — a spurious refusal under a genuine
                // race, never an over-grant. (Nothing is queried after the failed
                // statement: on Postgres that would be a poisoned transaction.)
                throw $sequence !== null
                    ? CustomerLimitReached::offer($offer->id, (string) $customerRef)
                    : RedemptionAlreadyRecorded::forOrder($offer->id, $orderRef);
            }

            foreach ($applied->lines as $line) {
                $this->recordLine($redemption, $line, $occurredAt);
            }

            $claimed = Offer::query()
                ->whereKey($offer->id)
                ->where(function ($query): void {
                    $query->whereNull('max_redemptions')->orWhereColumn('redemptions_used', '<', 'max_redemptions');
                })
                ->increment('redemptions_used');

            if ($claimed === 0) {
                throw OfferExhausted::offer($offer->id);
            }

            Event::dispatch(new RedemptionRecorded($redemption));

            return $redemption;
        });
    }

    private function recordLine(Redemption $redemption, LineAllocation $line, CarbonImmutable $occurredAt): void
    {
        RedemptionLine::query()->create([
            'redemption_id' => $redemption->id,
            'line_ref' => $line->lineRef,
            'product_ref' => $line->productRef === '' ? null : $line->productRef,
            'amount_minor' => $line->amountMinor,
            'created_at' => $occurredAt,
        ]);
    }

    /**
     * The slot this customer takes in the offer's per-customer allowance.
     *
     * Allocated only where a limit exists, and only for an identified shopper:
     * with no limit the index has nothing to enforce, and a null reference makes
     * the index inert, which is right — no customer means no per-customer limit.
     *
     * A released redemption gives its slot back (see {@see ReleaseRedemption}),
     * so this counts live rows.
     */
    private function nextCustomerSlot(Offer $offer, ?string $customerRef): ?int
    {
        if ($offer->max_redemptions_per_customer === null || $customerRef === null) {
            return null;
        }

        $live = Redemption::liveCustomerSlots($offer->id, $customerRef);

        if ($live >= $offer->max_redemptions_per_customer) {
            throw CustomerLimitReached::offer($offer->id, $customerRef);
        }

        return $live + 1;
    }
}
