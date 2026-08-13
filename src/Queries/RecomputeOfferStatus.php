<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Queries;

use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;

/**
 * Re-derive an offer's status from its decision log.
 *
 * Same reasoning as {@see RecomputeRedemptionsUsed}: the status column is a cache
 * of the newest decision, and a cache that cannot be checked against its source is
 * a second, quieter copy of the truth.
 */
final class RecomputeOfferStatus
{
    public function __invoke(int $offerId): ?OfferStatus
    {
        $decision = OfferStatusDecision::query()
            ->where('offer_id', $offerId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $decision?->to_status;
    }

    public function agrees(Offer $offer): bool
    {
        return $this($offer->id) === $offer->status;
    }
}
