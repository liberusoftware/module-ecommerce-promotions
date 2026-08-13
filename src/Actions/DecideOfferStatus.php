<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Events\OfferStatusDecided;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;
use Liberu\Ecommerce\Promotions\Queries\RecomputeOfferStatus;

/**
 * The only thing that writes `promotions_offers.status`.
 *
 * A status change is a decision with an actor, a time and a closed-enum reason —
 * not a boolean flipped in place. The host's answer to "who paused the Black
 * Friday sale, and when" is `discounts.is_active`, which records neither.
 *
 * The column is a cache of the newest decision. {@see RecomputeOfferStatus}
 * re-derives it from the log and a test proves they agree, for the same reason
 * the redemption counter is checkable: a cached value nobody can check is a value
 * nobody should trust.
 */
final class DecideOfferStatus
{
    public function __invoke(
        string $tenantId,
        int $offerId,
        OfferStatus $to,
        OfferStatusReason $reason,
        ?string $actorRef = null,
        ?string $note = null,
        ?CarbonImmutable $occurredAt = null,
    ): OfferStatusDecision {
        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $offerId, $to, $reason, $actorRef, $note, $occurredAt): OfferStatusDecision {
            $offer = Offer::query()->where('tenant_id', $tenantId)->find($offerId);

            if (! $offer instanceof Offer) {
                throw OfferNotFound::inTenant($tenantId, $offerId);
            }

            $decision = OfferStatusDecision::query()->create([
                'offer_id' => $offer->id,
                'from_status' => $offer->status,
                'to_status' => $to,
                'reason' => $reason,
                'actor_ref' => $actorRef,
                'note' => $note,
                'occurred_at' => $occurredAt,
            ]);

            $offer->forceFill(['status' => $to])->save();

            Event::dispatch(new OfferStatusDecided($offer, $decision));

            return $decision;
        });
    }
}
