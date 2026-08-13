<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferStatusReason;

/**
 * Who changed an offer's status, when, and why. Append-only.
 *
 * @property int $id
 * @property int $offer_id
 * @property OfferStatus|null $from_status
 * @property OfferStatus $to_status
 * @property OfferStatusReason $reason
 * @property string|null $actor_ref
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property-read Offer $offer
 */
class OfferStatusDecision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promotions_offer_status_decisions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => OfferStatus::class,
            'to_status' => OfferStatus::class,
            'reason' => OfferStatusReason::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
