<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Events;

use Liberu\Ecommerce\Promotions\Models\Offer;
use Liberu\Ecommerce\Promotions\Models\OfferRevision;

/**
 * An edit changed what happens next. It changed nothing that already happened:
 * every redemption names the revision it was evaluated under.
 */
final readonly class OfferTermsRevised
{
    public function __construct(public Offer $offer, public OfferRevision $revision) {}
}
