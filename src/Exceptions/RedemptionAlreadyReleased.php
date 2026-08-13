<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

final class RedemptionAlreadyReleased extends PromotionsException
{
    public static function redemption(int $redemptionId): self
    {
        return new self("Redemption [{$redemptionId}] is already released.");
    }

    public function errorCode(): string
    {
        return 'promotions.redemption_already_released';
    }

    public function status(): int
    {
        return 409;
    }
}
