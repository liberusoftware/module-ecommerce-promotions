<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

/**
 * Raised when the conditional update that claims a use affects zero rows — which
 * is the *only* thing that means exhausted. There is no count-then-insert and no
 * row lock: the host takes `lockForUpdate()` on the `coupons` row to serialise a
 * check that counts rows in `orders`, which works only because both writers
 * happen to route through one method.
 */
final class OfferExhausted extends PromotionsException
{
    public static function offer(int $offerId): self
    {
        return new self("Offer [{$offerId}] has no redemptions left.");
    }

    public function errorCode(): string
    {
        return 'promotions.offer_exhausted';
    }

    public function status(): int
    {
        return 409;
    }
}
