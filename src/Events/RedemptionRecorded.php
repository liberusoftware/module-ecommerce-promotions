<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Redemption;

/**
 * A specific offer was redeemed on a specific order. Nothing else can produce
 * this fact, which is why it is published.
 *
 * It is **not** campaign attribution, channel attribution, or an answer to "what
 * caused this sale" — those belong to Attribution and Analytics. This module
 * publishes the event and stops.
 */
final readonly class RedemptionRecorded
{
    public function __construct(public Redemption $redemption) {}
}
