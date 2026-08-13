<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Queries;

use Illuminate\Database\Eloquent\Collection;
use Liberu\Ecommerce\Promotions\Models\Redemption;

/**
 * Every offer spent on one order, with its lines and its release.
 *
 * The order reference is opaque and is matched as a string. Nothing here joins to
 * an orders table, because there is no orders table to join to.
 *
 * @phpstan-type RedemptionCollection Collection<int, Redemption>
 */
final class ListRedemptionsForOrder
{
    /** @return Collection<int, Redemption> */
    public function __invoke(string $tenantId, string $orderRef): Collection
    {
        return Redemption::query()
            ->where('tenant_id', $tenantId)
            ->where('order_ref', $orderRef)
            ->with(['lines', 'release'])
            ->orderBy('id')
            ->get();
    }
}
