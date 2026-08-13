<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

final class CodeAlreadyIssued extends PromotionsException
{
    public static function inTenant(string $tenantId, string $code): self
    {
        return new self("Code [{$code}] is already issued in tenant [{$tenantId}].");
    }

    public function errorCode(): string
    {
        return 'promotions.code_already_issued';
    }

    public function status(): int
    {
        return 409;
    }
}
