<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

use Liberu\Ecommerce\Promotions\Actions\DecideOfferStatus;
use Liberu\Ecommerce\Promotions\Queries\RecomputeOfferStatus;

/**
 * An offer's live status.
 *
 * The column is a cache of the newest row in `promotions_offer_status_decisions`,
 * which is the record of who changed it, when and why. Nothing writes this column
 * except {@see DecideOfferStatus}, and
 * {@see RecomputeOfferStatus} proves the two
 * agree.
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
