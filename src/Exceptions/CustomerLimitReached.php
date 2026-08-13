<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

final class CustomerLimitReached extends PromotionsException
{
    public static function offer(int $offerId, string $customerRef): self
    {
        return new self("Customer [{$customerRef}] has redeemed offer [{$offerId}] as often as it allows.");
    }

    public function errorCode(): string
    {
        return 'promotions.customer_limit_reached';
    }

    public function status(): int
    {
        return 409;
    }
}
