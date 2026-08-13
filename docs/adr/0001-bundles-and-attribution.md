# ADR 0001 — Where bundles, attribution and fraud controls belong

- **Status**: accepted
- **Date**: 2026-08-13
- **Context**: wave 12, `liberusoftware/ecommerce-promotions` 0.1.0
- **Decided here because Promotions is extracted first.** Bundles and Kits
  (#827), Attribution and Analytics (#821) and Fraud and Risk (#857) do not exist
  yet, so the boundary has to be drawn by the module that arrives first, in
  writing, rather than by whichever module happens to arrive second.

## 1. Bundles

### Context

The Promotions epic's scope list names "bundles". So does the Bundles and Kits
epic. The host has `ProductBundle`, which carries a `product_id` and a
`getBundlePrice()` that nothing calls.

Read as one word, "bundles" belongs to both modules, which means it belongs to
neither until somebody says which.

### Decision

**Split the word.**

- A **kit** is a sellable thing with its own identity and its own components. It
  has a product reference, it can be added to a basket as one line, it has stock
  behaviour of its own. The host's `ProductBundle` — which carries a `product_id`
  — is a kit. **Kits belong to Bundles and Kits (#827).**
- A **bundle promotion** is a rule that says *these things bought together cost
  less*. It creates nothing sellable; it reduces the price of things already in
  the basket. **Bundle promotions belong here**, and they are expressed as an
  offer whose terms name the products or collections that must be present.

### Consequence

Buy-X-get-Y in this package is the first bundle promotion, and the same shape
extends to "these three products together, 15% off" without a new concept. A kit
arriving from #827 appears to this module as an ordinary basket line with its own
`productRef`, and an offer may target it exactly as it targets any other product.

The test is: **does it create something a merchant can sell?** If yes it is a kit.
If it only makes existing things cheaper, it is an offer.

#827 is to be notified of this split.

## 2. Attribution

### Context

This module produces a fact nothing else can produce: *offer X was redeemed on
order Y at time T under revision R*. That fact is obviously interesting to
analytics, and the pull is to grow a campaign model here to make it more
interesting.

### Decision

**This module owns the redemption record and publishes
`RedemptionRecorded`. It stops there.**

It does **not** own campaign attribution, channel attribution, or any question of
the form "what caused this sale". Those belong to Attribution and Analytics
(#821).

### Consequence

There is no `campaign_id` on an offer, no channel, no source, no medium, and no
"first touch" or "last touch" anything. A campaign that wants to group offers can
do so through its own reference on its own side; if a merchant wants that
grouping visible here later, the honest form is an opaque `campaign_ref` string
this module never resolves — and even that waits until #821 asks for it.

The distinction that decides it: **a redemption is something that happened; an
attribution is a judgement about why.** This module records the first and has no
opinion on the second.

## 3. Fraud controls

### Context

Usage limits, per-customer limits and eligibility rules look like fraud controls
and are often bought as such.

### Decision

**This module owns limits and eligibility — how often, by whom, under what
conditions. It does not own risk scoring**, which belongs to Fraud and Risk
(#857).

### Consequence

**A limit is a rule the merchant wrote down. A risk score is a judgement.** Every
refusal this module produces can be traced to a term a merchant authored, and
`RefusalReason` is a closed enum of exactly those. Nothing here is probabilistic,
nothing here is trained, and nothing here refuses a shopper for a reason a
merchant cannot read off the offer.

If Fraud and Risk wants to stop a redemption it does so at its own layer, before
`ClaimRedemption` is called. This module will not grow a hook for it — a seam
here would make risk a precondition of redeeming, which puts a judgement inside a
record of fact.
