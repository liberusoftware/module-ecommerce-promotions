<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Queries;

use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionRelease;

/**
 * Re-derive `promotions_offers.redemptions_used` from the two append-only tables.
 *
 * A cached counter nobody can check is a number nobody should trust. The counter
 * exists because a conditional update is the only race-free way to enforce a
 * limit; this is what proves it has not drifted, and a test asserts the two agree
 * across claims, releases and erasure.
 */
final class RecomputeRedemptionsUsed
{
    public function __invoke(int $offerId): int
    {
        $redeemed = Redemption::query()->where('offer_id', $offerId)->count();

        $released = RedemptionRelease::query()
            ->whereIn('redemption_id', Redemption::query()->where('offer_id', $offerId)->select('id'))
            ->count();

        return $redeemed - $released;
    }

    /** Whether the cached counter still agrees with the ledger. */
    public function agrees(Offer $offer): bool
    {
        return $offer->redemptions_used === $this($offer->id);
    }
}
