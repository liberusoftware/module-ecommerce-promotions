<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

/**
 * A shopper's reference was cleared from this merchant's redemption history. The
 * redemptions, their lines and their releases survive: a merchant's usage limits
 * and reconciliation must not change because a shopper exercised a right.
 */
final readonly class CustomerRedactedFromRedemptions
{
    public function __construct(
        public string $tenantId,
        public string $customerRef,
        public int $redemptionsRedacted,
    ) {}
}
