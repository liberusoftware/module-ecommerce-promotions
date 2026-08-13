<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\Ecommerce\Promotions\Enums\ReleaseReason;

/**
 * A use given back. Append-only, and unique per redemption.
 *
 * @property int $id
 * @property int $redemption_id
 * @property ReleaseReason $reason
 * @property string|null $actor_ref
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property-read Redemption $redemption
 */
class RedemptionRelease extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promotions_redemption_releases';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => ReleaseReason::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Redemption, $this> */
    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }
}
