<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

/**
 * One offer is redeemed at most once per order, enforced by a unique index on
 * (tenant, offer, order reference) rather than by a guard in PHP. Every component
 * of that key is server-supplied, which is why claiming needs no client-held
 * idempotency key: one would be strictly weaker than the constraint already there.
 */
final class RedemptionAlreadyRecorded extends PromotionsException
{
    public static function forOrder(int $offerId, string $orderRef): self
    {
        return new self("Offer [{$offerId}] is already redeemed on order [{$orderRef}].");
    }

    public function errorCode(): string
    {
        return 'promotions.redemption_already_recorded';
    }

    public function status(): int
    {
        return 409;
    }
}
