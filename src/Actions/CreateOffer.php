<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;
use Liberu\Ecommerce\Promotions\Events\OfferCreated;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;

/**
 * Write a merchant's standing rule down, with its first revision and the decision
 * that created it.
 *
 * An offer starts `Draft`. Nothing evaluates it until somebody decides to
 * activate it, and that decision is recorded with an actor.
 */
final class CreateOffer
{
    public function __invoke(string $tenantId, OfferTerms $terms, ?string $actorRef = null, ?CarbonImmutable $occurredAt = null): Offer
    {
        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $terms, $actorRef, $occurredAt): Offer {
            $offer = Offer::query()->create(['tenant_id' => $tenantId] + Offer::columnsFor($terms));

            $revision = OfferRevision::query()->create([
                'offer_id' => $offer->id,
                'revision_number' => 1,
                'terms' => $terms->toArray(),
                'actor_ref' => $actorRef,
                'occurred_at' => $occurredAt,
            ]);

            $offer->forceFill(['current_revision_id' => $revision->id])->save();

            OfferStatusDecision::query()->create([
                'offer_id' => $offer->id,
                'from_status' => null,
                'to_status' => OfferStatus::Draft,
                'reason' => OfferStatusReason::Created,
                'actor_ref' => $actorRef,
                'occurred_at' => $occurredAt,
            ]);

            Event::dispatch(new OfferCreated($offer));

            return $offer;
        });
    }
}
