<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

/**
 * Why a redemption was given back. The host has no way to express any of these:
 * it counts orders, so a cancelled order spends a coupon forever.
 */
enum ReleaseReason: string
{
    case OrderCancelled = 'order_cancelled';
    case OrderRefunded = 'order_refunded';
    case MerchantReversed = 'merchant_reversed';
    case PaymentFailed = 'payment_failed';
}
