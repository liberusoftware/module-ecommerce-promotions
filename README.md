# Ecommerce Promotions

[![Tests](https://github.com/liberusoftware/module-ecommerce-promotions/actions/workflows/tests.yml/badge.svg)](https://github.com/liberusoftware/module-ecommerce-promotions/actions/workflows/tests.yml)

The merchant's standing rules for reducing what a shopper pays, the evaluation of
those rules against one basket at one moment, and the append-only record that a
rule was spent on an order.

A domain package for the Liberu ecommerce platform. No HTTP, no UI, no Filament —
the `-api`, `-filament` and `-livewire` packages sit on top of this one.

```bash
composer require liberusoftware/ecommerce-promotions
```

The package ships no `extra.laravel.providers`; installing it boots nothing.
Enable it by naming it in `MODULES_ENABLED`. See [docs/adoption.md](docs/adoption.md).

## Three things are called "a discount"

This package keeps them apart, and keeps a fourth thing apart from the first.

| | what it is | where it lives |
|---|---|---|
| **Offer** | the merchant's standing rule — *20% off orders over £50, until Friday* | `promotions_offers`, with an append-only revision archive and status decision log |
| **Code** | a way of *reaching* an offer. Many per offer, or none | `promotions_codes`, unique per merchant |
| **Entitlement** | the evaluation of the offers against one basket at one moment | **nowhere.** Derived, perishable, never stored |
| **Redemption** | the historical fact that an offer was spent on an order | `promotions_redemptions`, append-only, with its own release table |

## Quoting a basket

Writes nothing, stores nothing, reserves nothing. Re-quote on every basket
change.

```php
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Data\{Basket, BasketLine};

$entitlement = app(QuoteBasket::class)(
    tenantId: $merchantId,
    basket: new Basket(
        currency: 'GBP',
        lines: [
            new BasketLine(lineRef: 'line-1', productRef: 'sku-991', quantity: 2, unitAmountMinor: 1000),
            new BasketLine(lineRef: 'line-2', productRef: 'sku-457', quantity: 1, unitAmountMinor: 4999),
        ],
        shippingMinor: 499,
        customerRef: $customerId,
    ),
    codes: ['SUMMER10'],
);

$entitlement->totalMinor();                   // 1249
$entitlement->allocationForLine('line-1');    // 250 — where the money actually came off
$entitlement->shippingReductionMinor();       // published separately from the lines
$entitlement->skipped;                        // why each other offer did not apply (merchant-facing)
```

Then, inside the order transaction:

```php
foreach ($entitlement->applied as $applied) {
    app(ClaimRedemption::class)($merchantId, $applied, $orderRef, 'GBP', customerRef: $customerId);
}
```

## What it guarantees

- **The allocation sums to the total, exactly.** Largest remainder, ties broken by
  ascending line index. That rule is part of the published contract, not an
  implementation detail — tax and refunds both read the allocation, and a caller
  that re-derives the remainder differently is a penny out on every discounted
  order, forever. Proved by a property test over 400 generated baskets.
- **Money is integer minor units** with a currency and an exponent, together. A
  percentage is basis points. No float, no `decimal`, anywhere.
- **Usage limits are race-free without a lock.** Claiming a use is a conditional
  update, and zero affected rows means exhausted. Per-order and per-customer
  limits are unique indexes, not guards.
- **A use can be given back.** A release is its own append-only record with a
  closed-enum reason, so a cancelled order stops spending a code — and
  `RecomputeRedemptionsUsed` proves the cached counter still agrees with the
  ledger.
- **An edit changes the future, not the past.** Every redemption names the offer
  revision it was evaluated under.
- **Every code refusal is the same refusal.** Unknown, expired, exhausted, wrong
  merchant, ineligible — one message. The machine-readable reason is
  merchant-facing only; rendering it to a shopper turns the quote into an oracle
  for which codes exist.
- **Evaluation is deterministic.** Ascending `priority`, ties by ascending offer
  id, and tested to produce the same result on every run.
- **Free shipping is a real number**, published separately, because shipping is
  taxed and refunded differently from goods.
- **Buy X get Y discounts the cheapest qualifying units**, over groups of
  `buy + get` — the conventional rule, and the only one that does not reward a
  shopper for reordering their basket.

## What it does not own

No product, no price, no basket, no order, no shopper identity, no tax. It is
**told** the basket and never fetches one. The redemption's order reference is an
opaque string it cannot resolve and never joins to; there is no foreign key
anywhere outside this module's own tables, and `src/` contains no join at all.

Two optional seams, both **unbound by default**:

```php
interface ResolvesCustomerEligibility  // "is customer C in group/segment G?"
interface ResolvesProductGrouping      // "is product P in collection/category G?"
```

An offer that needs an absent seam is refused **by name**, and only that offer —
every other offer evaluates normally and the basket still gets its entitlement.
The blast radius of an unbound seam is the scope of the thing it controls. See
[docs/domain.md](docs/domain.md).

## Documentation

- [docs/domain.md](docs/domain.md) — the boundary, the four concepts, the units, the allocation rule, the seams, the custody proof
- [docs/adoption.md](docs/adoption.md) — wiring it into the host, and why no host table is adopted
- [docs/runbook.md](docs/runbook.md) — running it locally, CI, and the operational questions it answers
- [docs/adr/0001-bundles-and-attribution.md](docs/adr/0001-bundles-and-attribution.md) — where bundles, attribution and fraud controls belong

## Licence

MIT. See [LICENSE.md](LICENSE.md).
