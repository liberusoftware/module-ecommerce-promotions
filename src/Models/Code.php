<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A way of reaching an offer. Many per offer, or none.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $offer_id
 * @property string $code
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Offer $offer
 */
class Code extends Model
{
    protected $table = 'promotions_codes';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * One spelling, so SUMMER10 and summer10 are the same code rather than two
     * rows a merchant reads as a typo.
     */
    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
