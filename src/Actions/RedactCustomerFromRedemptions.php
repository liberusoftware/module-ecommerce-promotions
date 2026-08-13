<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Events\CustomerRedactedFromRedemptions;
use Liberu\Ecommerce\Promotions\Models\Redemption;

/**
 * Erasure redacts and keeps the shape.
 *
 * The customer reference is cleared; the redemption, its lines and its release
 * survive. A merchant's usage limits and reconciliation must not change because a
 * shopper exercised a right — the total redemption counter is untouched, and the
 * money still adds up.
 *
 * What does change is the *per-customer* allowance for that shopper: once nobody
 * knows it was them, it cannot be counted against them. That is a consequence of
 * erasure rather than a hole in it, and it is documented in docs/domain.md.
 */
final class RedactCustomerFromRedemptions
{
    public function __invoke(string $tenantId, string $customerRef): int
    {
        $redacted = DB::transaction(fn (): int => Redemption::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_ref', $customerRef)
            ->update(['customer_ref' => null, 'customer_sequence' => null]));

        Event::dispatch(new CustomerRedactedFromRedemptions($tenantId, $customerRef, $redacted));

        return $redacted;
    }
}
