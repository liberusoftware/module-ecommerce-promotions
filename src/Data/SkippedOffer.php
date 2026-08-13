<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Data;

use Liberu\Ecommerce\Promotions\Enums\RefusalReason;

/**
 * An active offer that did not apply, and why.
 *
 * Merchant-facing. `EligibilityUnresolvable` is a distinct outcome from "did not
 * qualify" precisely so a merchant whose segmented offer has silently reached
 * nobody for a week can find out without reading logs.
 */
final readonly class SkippedOffer
{
    public function __construct(
        public int $offerId,
        public string $offerName,
        public RefusalReason $reason,
    ) {}

    /** @return array{offer_id: int, offer_name: string, reason: string} */
    public function toArray(): array
    {
        return [
            'offer_id' => $this->offerId,
            'offer_name' => $this->offerName,
            'reason' => $this->reason->value,
        ];
    }
}
