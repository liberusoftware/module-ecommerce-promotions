<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Contracts;

/**
 * "Is product P in collection or category G?"
 *
 * Optional and unbound by default. Catalog owns collections and is built, but
 * this module may not import it — a module that can read the catalogue will
 * eventually decide what is in it.
 *
 * Absent, it removes a control exactly as
 * {@see ResolvesCustomerEligibility} does, and with the same consequence: an
 * offer targeting a collection does not apply, every other offer evaluates
 * normally, and the skip is visible to the merchant.
 */
interface ResolvesProductGrouping
{
    /**
     * @param  string  $productRef  An opaque reference this module never resolves.
     * @param  string  $groupRef  An opaque collection or category reference.
     */
    public function isProductIn(string $productRef, string $groupRef): bool;
}
