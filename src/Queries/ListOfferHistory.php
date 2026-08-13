<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Queries;

use Illuminate\Database\Eloquent\Collection;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;

/**
 * The two append-only logs behind one offer: what its terms were, and who decided
 * its status.
 *
 * This is the archive. Evaluation never reads it.
 */
final class ListOfferHistory
{
    /** @return Collection<int, OfferRevision> */
    public function revisions(int $offerId): Collection
    {
        return OfferRevision::query()->where('offer_id', $offerId)->orderBy('revision_number')->get();
    }

    /** @return Collection<int, OfferStatusDecision> */
    public function statusDecisions(int $offerId): Collection
    {
        return OfferStatusDecision::query()->where('offer_id', $offerId)->orderBy('occurred_at')->orderBy('id')->get();
    }
}
