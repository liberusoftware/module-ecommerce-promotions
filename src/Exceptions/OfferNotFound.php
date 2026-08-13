<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

final class OfferNotFound extends PromotionsException
{
    public static function inTenant(string $tenantId, int $offerId): self
    {
        return new self("No offer [{$offerId}] belongs to tenant [{$tenantId}].");
    }

    public function errorCode(): string
    {
        return 'promotions.offer_not_found';
    }

    public function status(): int
    {
        return 404;
    }
}
