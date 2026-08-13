<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Contracts;

/**
 * "Is customer C in group or segment G?"
 *
 * Optional and unbound by default. Promotions owns references to shoppers and
 * nothing else — Customers owns shoppers, groups and segments, and is not built.
 *
 * An unbound implementation removes a *control*: an eligibility rule that cannot
 * be resolved narrows nothing, and treating it as satisfied gives real money
 * away. So an offer naming a group is refused when this is absent — and **only
 * that offer**. The blast radius of an unbound seam is the scope of the thing it
 * controls, which is the finding this wave contributes; see docs/domain.md.
 */
interface ResolvesCustomerEligibility
{
    /**
     * @param  string  $customerRef  An opaque reference this module never resolves.
     * @param  string  $groupRef  An opaque group or segment reference.
     */
    public function isCustomerIn(string $customerRef, string $groupRef): bool;
}
