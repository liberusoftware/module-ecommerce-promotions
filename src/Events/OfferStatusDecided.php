<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferStatusDecision;

final readonly class OfferStatusDecided
{
    public function __construct(public Offer $offer, public OfferStatusDecision $decision) {}
}
