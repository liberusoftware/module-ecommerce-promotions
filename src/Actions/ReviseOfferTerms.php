<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Events\OfferTermsRevised;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;

/**
 * Change what happens next, without changing what already happened.
 *
 * The live terms move to the new values and the old ones are archived. Every
 * redemption already recorded still names the revision it was evaluated under, so
 * "an edit does not rewrite history" is provable rather than promised.
 */
final class ReviseOfferTerms
{
    public function __invoke(string $tenantId, int $offerId, OfferTerms $terms, ?string $actorRef = null, ?CarbonImmutable $occurredAt = null): OfferRevision
    {
        $occurredAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $offerId, $terms, $actorRef, $occurredAt): OfferRevision {
            $offer = Offer::query()->where('tenant_id', $tenantId)->find($offerId);

            if (! $offer instanceof Offer) {
                throw OfferNotFound::inTenant($tenantId, $offerId);
            }

            $revision = OfferRevision::query()->create([
                'offer_id' => $offer->id,
                'revision_number' => $offer->revision_number + 1,
                'terms' => $terms->toArray(),
                'actor_ref' => $actorRef,
                'occurred_at' => $occurredAt,
            ]);

            $offer->forceFill(Offer::columnsFor($terms) + [
                'revision_number' => $revision->revision_number,
                'current_revision_id' => $revision->id,
            ])->save();

            Event::dispatch(new OfferTermsRevised($offer, $revision));

            return $revision;
        });
    }
}
