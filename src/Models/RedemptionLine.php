<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a redemption came off one line.
 *
 * @property int $id
 * @property int $redemption_id
 * @property string $line_ref
 * @property string|null $product_ref
 * @property int $amount_minor
 * @property CarbonImmutable|null $created_at
 * @property-read Redemption $redemption
 */
class RedemptionLine extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promotions_redemption_lines';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Redemption, $this> */
    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }
}
