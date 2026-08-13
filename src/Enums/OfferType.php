<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

/**
 * What kind of reduction an offer makes.
 *
 * `FreeShipping` is a real outcome with a real number here, not the host's
 * `return 0; // Handled separately` — see docs/domain.md.
 */
enum OfferType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case FreeShipping = 'free_shipping';
    case BuyXGetY = 'buy_x_get_y';

    /** Whether the type's value is a rate in basis points rather than an amount. */
    public function usesBasisPoints(): bool
    {
        return $this === self::Percentage || $this === self::BuyXGetY;
    }
}
