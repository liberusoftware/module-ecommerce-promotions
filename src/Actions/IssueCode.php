<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Promotions\Events\CodeIssued;
use Liberu\Ecommerce\Promotions\Exceptions\CodeAlreadyIssued;
use Liberu\Ecommerce\Promotions\Exceptions\OfferNotFound;
use Liberu\Ecommerce\Promotions\Models\Code;
use Liberu\Ecommerce\Promotions\Models\Offer;

/**
 * Give an offer another way of being reached.
 *
 * Uniqueness is per merchant and enforced by the index, not by a lookup first: a
 * check-then-insert is not a constraint. The grain is the host's own reasoning —
 * a globally unique code is a land grab, not a correctness constraint.
 */
final class IssueCode
{
    public function __invoke(string $tenantId, int $offerId, string $code): Code
    {
        if (! Offer::query()->where('tenant_id', $tenantId)->whereKey($offerId)->exists()) {
            throw OfferNotFound::inTenant($tenantId, $offerId);
        }

        $normalised = Code::normalise($code);

        try {
            $issued = Code::query()->create([
                'tenant_id' => $tenantId,
                'offer_id' => $offerId,
                'code' => $normalised,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw CodeAlreadyIssued::inTenant($tenantId, $normalised);
        }

        Event::dispatch(new CodeIssued($issued));

        return $issued;
    }
}
