# Adopting `ecommerce-promotions` in the host application

## What this package does and does not take over

It takes over the merchant's standing rules, the evaluation of those rules
against a basket, and the record that a rule was spent. It does not take over the
cart, the order, the shopper or the tax calculation — it is told the basket and
hands back an allocation.

## Tables: no host table is adopted

Per `MODULE_DEVELOPMENT.md` §1.5, a table that existed in the host before the
package may keep its bare name. **This package adopts neither `coupons` nor
`discounts`, and every table it creates carries the `promotions_` prefix.**

Adopting a host table is a choice, not an obligation, and both candidates are
wrong in ways that matter:

- **`coupons` holds money as `decimal(10,2)` and `Coupon::$value` is cast
  `float`.** `CouponService` takes and returns `float` throughout. Money is
  integer minor units in this fleet and has been since wave 3.
- **`coupons` has no redemption record at all.** `max_uses` is enforced as
  `$this->orders()->count() < $this->max_uses` over another module's table, so a
  cancelled order, a fully refunded order and an order whose payment failed all
  spend the coupon permanently. There is nothing to migrate a redemption *from* —
  it has to be derived.
- **`discounts` carries the same fact in two columns five times over**
  (`usage_limits` beside `usage_limit`, `active_dates` beside
  `starts_at`/`ends_at`, `applies_once_per_customer` beside `once_per_customer`,
  `target_selection` beside `entitled_product_ids`/`entitled_collection_ids`,
  `customer_eligibility` beside `prerequisite_customer_ids` and
  `customer_group_id`). In every pair the schema offers both and the code reads
  one.
- **`discounts.value` is cast `decimal:2`**, which in Laravel returns a *string*
  and then goes into `min($this->value, $subtotal)` against a float.
- **Three of `Discount`'s four relations name schema that does not exist.**
  `orders()` derives `orders.discount_id`; `products()` names `discount_products`;
  `collections()` names `discount_collections`. No migration in the host creates
  any of the three.

So the host keeps its migrations and its tables, and this package brings its own.
Nothing needs deleting for the package to install.

## Wiring it up

### 1. Enable the module

```
MODULES_ENABLED="...,ecommerce-promotions"
```

The package ships no `extra.laravel.providers`, so Composer installing it boots
nothing. `ModuleManagerServiceProvider` registers the provider named in
`module.json` only when the module is enabled.

### 2. Run the migrations

The provider calls `loadMigrationsFrom()`, so `php artisan migrate` picks up all
seven tables.

### 3. Bind the seams — or do not

Both seams are optional and unbound by default. Binding them is what makes
segmented and collection-targeted offers usable.

```php
// AppServiceProvider::register()
$this->app->bind(
    \Liberu\Ecommerce\Promotions\Contracts\ResolvesCustomerEligibility::class,
    \App\Promotions\CustomerGroupEligibility::class,
);

$this->app->bind(
    \Liberu\Ecommerce\Promotions\Contracts\ResolvesProductGrouping::class,
    \App\Promotions\ProductCollectionGrouping::class,
);
```

The host already has both answers: `CustomerGroup::customers()` (through
`customer_group_memberships`) and `ProductCollection`. Neither may be imported by
this package, which is why they arrive as bindings.

**Leaving either unbound is a supported deployment.** The offers that name a
group, segment or collection will not apply, and they will be reported as
`eligibility_unresolvable` on the staff surface. Every other offer works. See
`docs/domain.md` for why that is the right blast radius.

### 4. Replace the coupon call sites

The host has one live path from a code to an order total —
`CartController::applyCoupon()` → session → `CheckoutController` →
`CheckoutService::resolveCouponDiscount()`.

Replace it with a quote on **every** basket change, and hold nothing:

```php
$entitlement = app(\Liberu\Ecommerce\Promotions\Actions\QuoteBasket::class)(
    tenantId: $store->getKey(),
    basket: new Basket(
        currency: 'GBP',
        lines: $lines,           // BasketLine per cart line, in cart order
        shippingMinor: $shippingMinor,
        customerRef: $customer?->getKey(),
    ),
    codes: $presentedCodes,
);
```

Then, inside the order transaction, claim each applied offer:

```php
foreach ($entitlement->applied as $applied) {
    app(\Liberu\Ecommerce\Promotions\Actions\ClaimRedemption::class)(
        tenantId: $store->getKey(),
        applied: $applied,
        orderRef: $order->getKey(),
        currency: 'GBP',
        customerRef: $customer?->getKey(),
    );
}
```

**Do not cache the entitlement.** `CartController::applyCoupon()` writes
`['code', 'discount', 'coupon_id']` into the session and `CheckoutController` was
subsequently taught to ignore that `discount` and recompute — the fix is right
and `CheckoutCouponRevalidationTest` pins it, but the wrong copy is still there
for the next surface to start from. Store the **code string** and nothing else;
re-quote on every read.

### 5. Give a use back when an order stops being an order

The host cannot do this at all. Wherever an order is cancelled, fully refunded or
fails payment:

```php
foreach (app(ListRedemptionsForOrder::class)($tenantId, $order->getKey()) as $redemption) {
    app(ReleaseRedemption::class)($redemption->id, ReleaseReason::OrderCancelled, $actorRef);
}
```

### 6. Retire the dead ends

Once the call sites move:

- `Discount`, `DiscountResource` and its pages can go. `DiscountResource::form()`
  returns `->components([//])` — an empty schema over a table whose `title` is
  `NOT NULL` — so nothing is lost.
- `CouponService::getActiveCoupons()` should go rather than be ported. It filters
  `valid_from <= now AND valid_until >= now`, which **drops every unbounded
  coupon**, while `Coupon::isValid()` correctly treats a null bound as unbounded.
  Two definitions of "active" that disagree on null.
- `CheckoutService::assertCouponAvailable()` should go. It takes
  `lockForUpdate()` on the `coupons` row to serialise a check that counts rows in
  `orders`, and returns silently when the code resolves to nothing. The
  conditional update in `ClaimRedemption` replaces it and needs no lock.

## Migrating existing data

There is no automatic migration, and one would be dishonest about what it could
recover. The shape of it:

1. **`coupons` → one offer plus one code each.** `type` maps to
   `percentage`/`fixed_amount`; `value` becomes basis points (`value * 100`) or
   minor units (`value * 100` as a string parse, never `(int) ($value * 100)`);
   `valid_from`/`valid_until` become `starts_at`/`ends_at`; `max_uses` becomes
   `max_redemptions`; `min_purchase_amount` becomes `minimum_subtotal_minor`. The
   code becomes a `promotions_codes` row. `store_id` becomes `tenant_id`.
2. **Backfill `redemptions_used`** from `orders.coupon_code` joined on
   `orders.store_id`, which is what the host counts today. Historical redemptions
   cannot be reconstructed line by line, because the host never recorded an
   allocation — backfill the counter, not the ledger, and let the ledger start
   from the cutover.
3. **`discounts` needs a human.** A merchant configured targeting, prerequisites,
   allocation methods and eligibility that nothing ever read, so the rows say what
   a merchant *wanted* rather than what ever happened. Migrating them silently
   would turn a decorative configuration into live money. Export them and have
   someone re-author the ones that are still wanted.

## Contested placements, settled here

See `docs/adr/0001-bundles-and-attribution.md`.
