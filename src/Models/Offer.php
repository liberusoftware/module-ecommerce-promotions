<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\Ecommerce\Promotions\Data\Money;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferStatus;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;

/**
 * The merchant's standing rule.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property OfferType $type
 * @property OfferTarget $target
 * @property StackingMode $stacking
 * @property OfferStatus $status
 * @property int|null $value_basis_points
 * @property int|null $value_minor
 * @property string|null $currency
 * @property int|null $currency_exponent
 * @property int|null $minimum_subtotal_minor
 * @property int|null $minimum_quantity
 * @property list<string>|null $product_refs
 * @property list<string>|null $collection_refs
 * @property list<string>|null $customer_group_refs
 * @property int|null $buy_quantity
 * @property int|null $get_quantity
 * @property int $priority
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $max_redemptions
 * @property int|null $max_redemptions_per_customer
 * @property int $redemptions_used
 * @property int $revision_number
 * @property int|null $current_revision_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, Code> $codes
 * @property-read Collection<int, OfferRevision> $revisions
 * @property-read Collection<int, OfferStatusDecision> $statusDecisions
 * @property-read Collection<int, Redemption> $redemptions
 */
class Offer extends Model
{
    protected $table = 'promotions_offers';

    protected $guarded = [];

    /**
     * Restated as attribute defaults as well as column defaults: `create()` does
     * not read a column default back, so a freshly created model would otherwise
     * report null for both of these until it was refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'priority' => 0,
        'redemptions_used' => 0,
        'revision_number' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OfferType::class,
            'target' => OfferTarget::class,
            'stacking' => StackingMode::class,
            'status' => OfferStatus::class,
            'value_basis_points' => 'integer',
            'value_minor' => 'integer',
            'currency_exponent' => 'integer',
            'minimum_subtotal_minor' => 'integer',
            'minimum_quantity' => 'integer',
            'product_refs' => 'array',
            'collection_refs' => 'array',
            'customer_group_refs' => 'array',
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'max_redemptions' => 'integer',
            'max_redemptions_per_customer' => 'integer',
            'redemptions_used' => 'integer',
            'revision_number' => 'integer',
            'current_revision_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Code, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(Code::class);
    }

    /** @return HasMany<OfferRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class);
    }

    /** @return HasMany<OfferStatusDecision, $this> */
    public function statusDecisions(): HasMany
    {
        return $this->hasMany(OfferStatusDecision::class);
    }

    /** @return HasMany<Redemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /** The live terms, read from the columns — never from the revision archive. */
    public function terms(): OfferTerms
    {
        $currency = $this->currency;
        $exponent = $this->currency_exponent ?? 2;

        return new OfferTerms(
            name: $this->name,
            type: $this->type,
            target: $this->target,
            stacking: $this->stacking,
            description: $this->description,
            valueBasisPoints: $this->value_basis_points,
            valueAmount: $this->value_minor !== null && $currency !== null
                ? Money::fromMinor($this->value_minor, $currency, $exponent)
                : null,
            minimumSubtotal: $this->minimum_subtotal_minor !== null && $currency !== null
                ? Money::fromMinor($this->minimum_subtotal_minor, $currency, $exponent)
                : null,
            minimumQuantity: $this->minimum_quantity,
            productRefs: array_values($this->product_refs ?? []),
            collectionRefs: array_values($this->collection_refs ?? []),
            customerGroupRefs: array_values($this->customer_group_refs ?? []),
            buyQuantity: $this->buy_quantity,
            getQuantity: $this->get_quantity,
            priority: $this->priority,
            startsAt: $this->starts_at,
            endsAt: $this->ends_at,
            maxRedemptions: $this->max_redemptions,
            maxRedemptionsPerCustomer: $this->max_redemptions_per_customer,
        );
    }

    /** The column values a set of terms implies. @return array<string, mixed> */
    public static function columnsFor(OfferTerms $terms): array
    {
        return [
            'name' => $terms->name,
            'description' => $terms->description,
            'type' => $terms->type,
            'target' => $terms->target,
            'stacking' => $terms->stacking,
            'value_basis_points' => $terms->valueBasisPoints,
            'value_minor' => $terms->valueAmount?->minor,
            'currency' => $terms->currency(),
            'currency_exponent' => $terms->currencyExponent(),
            'minimum_subtotal_minor' => $terms->minimumSubtotal?->minor,
            'minimum_quantity' => $terms->minimumQuantity,
            'product_refs' => $terms->productRefs,
            'collection_refs' => $terms->collectionRefs,
            'customer_group_refs' => $terms->customerGroupRefs,
            'buy_quantity' => $terms->buyQuantity,
            'get_quantity' => $terms->getQuantity,
            'priority' => $terms->priority,
            'starts_at' => $terms->startsAt,
            'ends_at' => $terms->endsAt,
            'max_redemptions' => $terms->maxRedemptions,
            'max_redemptions_per_customer' => $terms->maxRedemptionsPerCustomer,
        ];
    }
}
