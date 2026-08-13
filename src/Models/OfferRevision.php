<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archived set of terms. Append-only: there is no UPDATED_AT because nothing
 * updates a revision, and evaluation never reads this table.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $revision_number
 * @property array<string, mixed> $terms
 * @property string|null $actor_ref
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property-read Offer $offer
 */
class OfferRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promotions_offer_revisions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'terms' => 'array',
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
