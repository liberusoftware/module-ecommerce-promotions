# Changelog

All notable changes to `liberusoftware/ecommerce-promotions` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-13

First release. Extracted from the host application's `Coupon`, `CouponService`
and `Discount`, and reshaped around the distinction those three collapse: an
offer, a code, an entitlement and a redemption are four things.

### Added

- **Offers.** A merchant's standing rule, with queryable live terms, an
  append-only revision archive, and a status that is a decision record with an
  actor, a time and a closed-enum reason rather than a boolean flipped in place.
- **Codes.** A separate way of *reaching* an offer. Many per offer, or none — an
  automatic discount is an offer with no code. Unique per merchant.
- **Entitlements.** Evaluation of a basket at one moment. Derived, perishable and
  never stored. Publishes a per-line allocation plus a separate shipping
  reduction, never a bare total.
- **Largest-remainder allocation**, ties broken by ascending line index, proved by
  a property test to sum to the entitlement total exactly for every basket, rate
  and line count. This is part of the published contract.
- **Redemptions.** Append-only, with the offer revision they were evaluated under.
  The total usage limit is claimed by a conditional update — zero affected rows
  means exhausted — and the per-order and per-customer limits are unique indexes.
- **Releases.** A separate append-only record that gives a use back, with a closed
  enum reason. `RecomputeRedemptionsUsed` re-derives the cached counter from the
  two tables, and a test proves they agree.
- **Free shipping** as a real number published separately from the line
  allocation, because shipping is taxed and refunded differently from goods.
- **Buy X get Y** discounting the cheapest qualifying units, over groups of
  `buy + get`, with the rate read as basis points.
- **Two optional seams**, `ResolvesCustomerEligibility` and
  `ResolvesProductGrouping`, both unbound by default. An offer that names a group,
  segment or collection with the relevant seam absent is refused by name and
  reported to the merchant as `eligibility_unresolvable`; every other offer
  evaluates normally.
- **Erasure** that redacts the customer reference and keeps the redemption, its
  lines and its release.
- Money as integer minor units with a currency and an exponent throughout.
  Percentages as basis points.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-promotions/releases/tag/0.1.0
