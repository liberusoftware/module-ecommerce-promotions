<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

use Liberu\Ecommerce\Promotions\Data\Money;

final class CurrencyMismatch extends PromotionsException
{
    public static function between(Money $left, Money $right): self
    {
        return new self("Cannot combine {$left->currency} with {$right->currency}.");
    }

    public static function withBasket(string $offerCurrency, string $basketCurrency): self
    {
        return new self("An offer priced in {$offerCurrency} cannot reduce a {$basketCurrency} basket.");
    }

    public function errorCode(): string
    {
        return 'promotions.currency_mismatch';
    }

    public function status(): int
    {
        return 422;
    }
}
