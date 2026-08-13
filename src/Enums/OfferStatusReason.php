<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

/**
 * Why an offer's status changed. Closed, because "who paused the Black Friday
 * sale, and when" is a question somebody asks at 9am on Black Friday, and a free
 * text field is not an answer you can group by.
 */
enum OfferStatusReason: string
{
    case Created = 'created';
    case MerchantActivated = 'merchant_activated';
    case MerchantPaused = 'merchant_paused';
    case MerchantResumed = 'merchant_resumed';
    case MerchantEnded = 'merchant_ended';
    case Exhausted = 'exhausted';
}
