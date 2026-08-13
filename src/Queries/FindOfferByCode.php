<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Queries;

use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * Resolve a code to its offer within one merchant.
 *
 * **Staff use only.** Handing this to a shopper-facing surface reintroduces the
 * enumeration oracle that `CodeRefused` closes: "this code exists but is expired"
 * and "no such code" must be one answer to a shopper. Quoting is the shopper
 * path, and it never leaks which of the two happened.
 */
final class FindOfferByCode
{
    public function __invoke(string $tenantId, string $code): ?Offer
    {
        return Offer::query()
            ->whereIn('id', Code::query()
                ->where('tenant_id', $tenantId)
                ->where('code', Code::normalise($code))
                ->select('offer_id'))
            ->first();
    }
}
