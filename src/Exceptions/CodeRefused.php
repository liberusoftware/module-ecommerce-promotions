<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Exceptions;

use Liberu\Ecommerce\Promotions\Enums\RefusalReason;

/**
 * A code was refused. **Every** refusal carries the same message.
 *
 * Unknown code, wrong merchant, expired, not yet started, exhausted, already used
 * by this shopper, basket ineligible, minimum not met — one answer. Enumeration
 * is closed by making every wrong answer the same answer (wave 7's gift-card
 * rule). The host breaks this in the one place it identified the threat:
 * `CouponService::validateAndApplyCoupon()` returns "Invalid coupon code." for an
 * unknown code and "This coupon has expired or reached its usage limit." for a
 * real one, which tells an attacker which codes exist.
 *
 * {@see reason()} is for the **merchant-facing surface only**. A shopper-facing
 * surface must render {@see getMessage()} and nothing else.
 */
final class CodeRefused extends PromotionsException
{
    /** The one message. Do not add a second. */
    public const MESSAGE = 'That code cannot be applied to this basket.';

    private RefusalReason $reason = RefusalReason::UnknownCode;

    public static function because(RefusalReason $reason): self
    {
        $refusal = new self(self::MESSAGE);
        $refusal->reason = $reason;

        return $refusal;
    }

    /** Merchant-facing only. Never render this to a shopper. */
    public function reason(): RefusalReason
    {
        return $this->reason;
    }

    public function errorCode(): string
    {
        return 'promotions.code_refused';
    }

    public function status(): int
    {
        return 422;
    }
}
