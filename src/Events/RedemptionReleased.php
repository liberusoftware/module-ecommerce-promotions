<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Redemption;
use Liberu\Ecommerce\Promotions\Models\RedemptionRelease;

final readonly class RedemptionReleased
{
    public function __construct(public Redemption $redemption, public RedemptionRelease $release) {}
}
