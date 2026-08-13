<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

/**
 * Terms a merchant cannot mean. The host accepts all of these: `DiscountResource`
 * renders a form with no fields over a table whose `title` is NOT NULL, and half
 * the columns it does have are read by nothing.
 */
final class InvalidOfferTerms extends PromotionsException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function errorCode(): string
    {
        return 'promotions.invalid_offer_terms';
    }

    public function status(): int
    {
        return 422;
    }
}
