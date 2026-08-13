# The Promotions domain

## The boundary

Promotions owns the merchant's standing rules for reducing what a shopper pays,
the evaluation of those rules against one basket at one moment, and the record
that a rule was spent on an order.

It owns no product, no price, no basket, no order, no shopper identity, and no
tax.

It is **told** the basket. It never fetches one. Product reference, quantity, unit
amount and currency all arrive as arguments. There is no seam for "read the
cart", because a module that can read a cart will eventually decide what is in
it.

| neighbour | owns | this module owns |
|---|---|---|
| Catalog | products, categories, collections | *references* to products and collections, resolved through a seam |
| Pricing | price lists, price points, the price a line starts at | the reduction applied to that line, never the price itself |
| Cart | the basket and its lifecycle | an evaluation of a basket handed to it |
| Checkout / Orders | the order, its totals, its lifecycle | a redemption naming an order it cannot resolve |
| Customers | shoppers, groups, segments | *references* to shoppers, and eligibility resolved through a seam |
| Gift Cards | a bearer credential that is **money already paid** | a rule that reduces what is owed |

**A gift card is tender; a promotion is a price reduction.** They land in
different places on an order, they are refunded differently, and tax lands on the
amount after a promotion and before a gift card. If a promotion is being modelled
as a payment, something has gone wrong.

**Loyalty's `discount_percentage` is Loyalty's rule, not this module's.** When
Loyalty is extracted it will express its rule as an offer through this public
surface, or it will not. Either way this module does not reach for it.

## The shaping idea: three different things are called "a discount"

**An offer** is the merchant's standing rule — *20% off orders over £50, until
Friday, one per customer.* It is a policy. It has an owner, a lifetime and a
status. It is edited, paused and ended, and its edits change what happens next
without changing what already happened.

**An entitlement** is the evaluation of the offers against **one basket at one
moment** — *this basket qualifies for £12.40 off, allocated £8.10 to line 1 and
£4.30 to line 2.* It is derived and perishable. It is not stored, held, reserved
or carried between requests: a basket that shrinks loses the entitlement it had,
and a basket that grows may gain one.

**A redemption** is the historical fact that an offer was spent on an order. It
is append-only. It is what a usage limit counts, what a customer limit counts,
and what an accountant reconciles.

And a fourth thing is kept separate from the offer: **the code**. A code is a way
of *reaching* an offer, not the offer itself. One offer may be reachable by many
codes — a per-customer unique code, a campaign code, a partner code — or by none
at all, which is exactly what an automatic discount is.

The host collapses all three: the coupon row *is* the rule and the code, the
applied amount is a session number, and a "use" is a `SELECT COUNT(*)` over
orders. There is no third table at all, which is why a cancelled order can never
give a use back.

## Units

**Money is integer minor units plus a currency plus an exponent, stored
together.** Never a float, never a bare integer, never `decimal:2` cast to a
string and then used in arithmetic.

**A percentage is basis points**, an integer. 20% is `2000`. A rate held as
`decimal:2` cannot express a third off, and rounding a rate before applying it to
money loses more than rounding the result.

## The allocation, and the thing that must sum exactly

An entitlement's output is an **allocation**, not a number. It publishes a
per-line minor amount and, separately, a shipping reduction. It never publishes
only a total, because two callers need the breakdown and neither can re-derive
it:

- **Tax reads it.** The tax engine spreads a discount pro-rata across lines with
  untaxable lines in the denominator — a correction made *for* a caller that only
  had a single number. Now that this module can say which line the money came
  off, an offer that genuinely targets one product is allocated to that product,
  and only an order-level offer is spread.
- **Refunds read it.** Refunding one line of a discounted order requires knowing
  how much of the discount that line carried.

**The allocation sums to the entitlement's total exactly.** Distributing a
reduction across lines leaves a remainder in minor units, and where that
remainder goes is **part of the published contract, not an implementation
detail** — a caller that re-derives it differently produces a line total that
disagrees with the order total by a penny, forever, on every discounted order.

> **The rule: largest remainder, ties broken by ascending line index (basket
> order).** Floor each line's pro-rata share, then hand the leftover pennies to
> the largest fractional remainders.

`tests/Unit/AllocatorTest.php` proves the sum property over a generated corpus of
400 baskets rather than over three hand-picked examples.

Buy-X-get-Y needs no allocator: every penny is attributed to the unit it came
off, so the per-line sum is the total by construction.

## Settled decisions

1. **Offer, code, entitlement and redemption are four concepts and four
   different shapes.** An entitlement is never persisted.
2. **An offer's live terms are queryable columns on the offer.**
   `promotions_offer_revisions` records every change with its actor and
   `occurred_at`. **Evaluation never reads the revision table** — it is an
   archive, and a second readable copy of the live terms would be the host's
   duplicated-column fault with better provenance. A redemption records the
   revision id it was evaluated under, and that is what makes "an edit changes
   the future, not the past" provable.
3. **An offer's status is its own append-only decision record**, with an actor,
   an `occurred_at` and a closed enum reason. The `status` column is a cache of
   the newest decision; `RecomputeOfferStatus` re-derives it and a test proves
   they agree.
4. **A total usage limit is enforced by a conditional update, never by
   count-then-insert.** Claiming a use is
   `UPDATE … SET redemptions_used = redemptions_used + 1 WHERE id = ? AND (max_redemptions IS NULL OR redemptions_used < max_redemptions)`,
   and **zero affected rows means exhausted**. That is race-free without a lock.
   Per-customer and per-order limits are **unique indexes**, not guards.
5. **A redemption is append-only, and a release is a separate append-only
   record**, with an actor, a closed-enum reason and a unique index on the
   redemption id so one redemption is released at most once. Releasing decrements
   the counter by the same conditional update. `RecomputeRedemptionsUsed`
   re-derives the counter from the two tables — a cached counter nobody can check
   is a number nobody should trust.
6. **Every offer declares its stacking behaviour explicitly**: `exclusive` (if it
   applies, nothing else may) or `stackable`. There is no implicit answer and no
   global setting. Evaluation order is **ascending `priority`, ties broken by
   ascending offer id**, and it is deterministic and tested — two offers that both
   apply must produce the same result on every run, or a merchant's revenue
   depends on row order.
7. **An offer that cannot be evaluated is refused by name**, and the refusal names
   the offer rather than failing the request.
8. **A code is refused with one message.** Unknown code, wrong merchant, expired,
   not yet started, exhausted, already used by this shopper, basket ineligible,
   minimum not met — all produce the same `CodeRefused`, whose message is a
   constant. The machine-readable `reason()` is **for the merchant-facing surface
   only**, and a shopper-facing surface must not render it. Enumeration is closed
   by making every wrong answer the same answer.
9. **Free shipping is a real outcome with a real number**, published separately
   from the line allocation, because shipping is taxed and refunded differently
   from goods.
10. **Buy X get Y discounts the cheapest qualifying units.** A group is `buy +
    get` units, so three-for-two means paying for two of every three. Cheapest
    first is the conventional rule and the only one that does not reward a shopper
    for reordering their basket.
11. **This module never sees a price it did not receive as an argument, and never
    writes to any table it does not own.** The redemption's `order_ref` is an
    opaque string it cannot resolve and never joins to.
12. **Erasure redacts and keeps the shape.** A redemption's customer reference is
    nullable and clearable; the redemption, its lines and its release survive,
    because a merchant's usage limits and reconciliation must not change because a
    shopper exercised a right. What *does* change is that shopper's per-customer
    allowance — once nobody knows it was them, it cannot be counted against them.
    That is a consequence of erasure, not a hole in it.

## The seams, and the rule this wave states

```php
interface ResolvesCustomerEligibility  // "is customer C in group/segment G?"
interface ResolvesProductGrouping      // "is product P in collection/category G?"
```

Both are optional and **unbound by default**. Both are bound by the host, and
both may be absent.

Wave 11 stated the rule: an unbound optional seam is safe when its absence
removes a *claim*, and unsafe when its absence removes a *control*.
`ConfirmsPurchase` unbound was a valid deployment; `ScreensContent` unbound was a
503.

**Both of this module's seams remove a control** — each one *narrows* who
qualifies, so treating an unresolvable eligibility rule as satisfied is a
giveaway, and a giveaway of real money. By wave 11's rule that reads as "503 the
request". **That is wrong here, and it is this wave's finding:**

> **The blast radius of an unbound seam is the scope of the thing it controls.**
> `ScreensContent` controls every submission, so its absence fails the request.
> `ResolvesCustomerEligibility` controls *the offers that name a segment* — so its
> absence must fail **those offers**, and only those. Failing the checkout of a
> shopper who was not using a segmented offer, on a deployment that simply has no
> segments, would be a refusal with nothing behind it.

Concretely:

- An offer whose terms name a group, segment or collection, evaluated with the
  relevant seam unbound, **does not apply**, and the entitlement records it as
  skipped with `eligibility_unresolvable`.
- Every other offer evaluates normally and the basket gets its entitlement.
- The skip is **visible to the merchant**, and it is a distinct outcome from
  `customer_not_eligible`. A merchant whose Black Friday VIP offer has silently
  applied to nobody for a week must be able to find out why without reading logs.
- The shopper is told only that the code was refused, in the one message.

All four are proved in `tests/Feature/SeamTest.php`.

## The custody proof

Three properties, proved by the suite rather than asserted here — see
`tests/Feature/CustodyTest.php`.

1. **It owns no catalogue.** The domain evaluates a basket referencing product
   `987654321`, which nothing in its database has heard of, and produces a correct
   allocation. No table has a foreign key to any product.
2. **It owns no order.** A redemption is recorded against `ord_not_a_real_order`,
   queried and released, and no order is ever resolved. `promotions_redemptions`
   has no foreign key outside this module, and `src/` contains no join at all.
3. **It owns no shopper.** The whole suite runs with both seams unbound, and every
   offer that does not name a segment behaves identically to a run with them
   bound.

## Known limits, deliberately left for 0.2.0

- **Per-customer slot allocation fails closed under a genuine race.** Two
  concurrent claims for the same shopper can compute the same slot number; the
  unique index rejects one, and it is reported as `CustomerLimitReached` even
  where a further slot was free. A spurious refusal under contention, never an
  over-grant. A bounded retry would remove it.
- **A scheduled activation is not a status decision.** An offer becomes live when
  someone decides it does; `starts_at` and `ends_at` gate evaluation but do not
  write a decision row. An offer that has passed its `ends_at` still reads as
  `Active` with every quote refusing it as `ended`. Sweeping those to `Ended` with
  reason `schedule_elapsed` needs a scheduler this package does not own.
- **`OfferStatusReason::Exhausted` is declared and never written.** Nothing
  currently ends an offer automatically when its last use is claimed. It is in the
  enum because a merchant surface should be able to record that decision, and
  writing it belongs with the scheduler above.
