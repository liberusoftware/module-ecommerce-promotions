<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Offer;

final readonly class OfferCreated
{
    public function __construct(public Offer $offer) {}
}
