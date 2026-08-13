<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Liberu\Ecommerce\Promotions\Data\Money;

/**
 * An offer spent on an order. Append-only.
 *
 * `order_ref` is opaque: this module cannot resolve it, does not join to it, and
 * ships no orders table to join to.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $offer_id
 * @property int $offer_revision_id
 * @property int|null $code_id
 * @property string $order_ref
 * @property string|null $customer_ref
 * @property int|null $customer_sequence
 * @property string $currency
 * @property int $currency_exponent
 * @property int $line_reduction_minor
 * @property int $shipping_reduction_minor
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property-read Offer $offer
 * @property-read OfferRevision $revision
 * @property-read Code|null $code
 * @property-read Collection<int, RedemptionLine> $lines
 * @property-read RedemptionRelease|null $release
 */
class Redemption extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'promotions_redemptions';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'shipping_reduction_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_sequence' => 'integer',
            'currency_exponent' => 'integer',
            'line_reduction_minor' => 'integer',
            'shipping_reduction_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<OfferRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(OfferRevision::class, 'offer_revision_id');
    }

    /** @return BelongsTo<Code, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }

    /** @return HasMany<RedemptionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RedemptionLine::class);
    }

    /** @return HasOne<RedemptionRelease, $this> */
    public function release(): HasOne
    {
        return $this->hasOne(RedemptionRelease::class);
    }

    /**
     * How many of an offer's per-customer slots this shopper currently holds.
     *
     * One definition, used by both the gate and the claim, because two spellings
     * of "how many has this customer had" is how they come to disagree — the host
     * has exactly that fault between `Coupon::isValid()` and
     * `CouponService::getActiveCoupons()`, which differ on null bounds.
     *
     * A slot is a constraint marker, not a fact: {@see RedemptionRelease} hands it
     * back by clearing it, and the redemption, its lines and its release all
     * survive.
     */
    public static function liveCustomerSlots(int $offerId, string $customerRef): int
    {
        return static::query()
            ->where('offer_id', $offerId)
            ->where('customer_ref', $customerRef)
            ->whereNotNull('customer_sequence')
            ->count();
    }

    public function totalMinor(): int
    {
        return $this->line_reduction_minor + $this->shipping_reduction_minor;
    }

    public function total(): Money
    {
        return Money::fromMinor($this->totalMinor(), $this->currency, $this->currency_exponent);
    }
}
