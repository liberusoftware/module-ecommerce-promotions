<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Enums;

use Liberu\Ecommerce\Promotions\Exceptions\CodeRefused;

/**
 * Why an offer did not apply, or why a presented code was refused.
 *
 * One enum serves both because they are the same question asked from two sides:
 * a refused code is a code whose offer was skipped. **This is merchant-facing
 * only.** A shopper is told the one message from
 * {@see CodeRefused} and nothing else —
 * rendering these to a shopper turns the quote endpoint into an oracle for which
 * codes exist, which is wave 7's gift-card rule and a security decision rather
 * than a UX one.
 */
enum RefusalReason: string
{
    /** No code with that value belongs to this merchant. */
    case UnknownCode = 'unknown_code';

    /** The offer is reachable only by a code, and none was presented. */
    case CodeNotPresented = 'code_not_presented';

    case NotYetStarted = 'not_yet_started';
    case Ended = 'ended';

    /** The offer's total redemption limit is spent. */
    case Exhausted = 'exhausted';

    /** This shopper has already redeemed the offer as often as it allows. */
    case CustomerLimitReached = 'customer_limit_reached';

    /** The offer names a group or segment this shopper is not in. */
    case CustomerNotEligible = 'customer_not_eligible';

    /**
     * The offer names a group, segment or collection and the seam that answers
     * for it is not bound. A control was removed, so the offer does not apply —
     * and this is deliberately *not* `CustomerNotEligible`, because a merchant
     * whose VIP offer has reached nobody for a week has to be able to tell the
     * difference without reading logs.
     */
    case EligibilityUnresolvable = 'eligibility_unresolvable';

    case MinimumNotMet = 'minimum_not_met';

    /** Nothing in the basket is what the offer targets. */
    case NoQualifyingLines = 'no_qualifying_lines';

    /** The targets matched, but the reduction came to nothing. */
    case NothingToReduce = 'nothing_to_reduce';

    /** An exclusive offer applied, so this one may not. */
    case BlockedByExclusive = 'blocked_by_exclusive';

    /** The offer is denominated in a currency the basket is not. */
    case CurrencyMismatch = 'currency_mismatch';
}
