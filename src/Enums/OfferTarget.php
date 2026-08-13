<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

/**
 * Which part of a basket an offer reduces.
 *
 * This decides the allocation, not just the arithmetic: an `Order` offer is
 * spread pro-rata across every line, and a `Product` or `Collection` offer is
 * allocated only to the lines it names. Wave 3's tax engine had to spread a
 * single number because the host could not say which line the money came off.
 */
enum OfferTarget: string
{
    case Order = 'order';
    case Product = 'product';
    case Collection = 'collection';
    case Shipping = 'shipping';
}
