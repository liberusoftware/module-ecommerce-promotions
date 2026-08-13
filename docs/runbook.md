# Runbook

## Working on this package locally

`composer install` **cannot run in this environment.** PHP's libcurl is built
against c-ares, which ignores the `options use-vc` in `/etc/resolv.conf` that
makes DNS work here; `curl`, `git` and `gh` are unaffected because glibc honours
it.

The suite still runs locally, without Composer, by borrowing a complete vendor
tree:

```bash
cp -a /home/tom/code/package-testbench/vendor .
mkdir -p vendor/liberusoftware
ln -sfn /home/tom/code/package-testbench vendor/liberusoftware/package-testbench
cp /home/tom/code/package-testbench/vendor/autoload.php vendor/autoload_generated.php
```

Then hand-write `vendor/autoload.php` to correct the PSR-4 roots — the borrowed
tree maps `Liberu\PackageTestbench\` at *this* package's `src/`, because Composer
generated it for a different root package:

```php
<?php
$loader = require __DIR__.'/autoload_generated.php';
$loader->setPsr4('Liberu\\PackageTestbench\\', ['/home/tom/code/package-testbench/src']);
$loader->setPsr4('Liberu\\PackageTestbench\\Tests\\', ['/home/tom/code/package-testbench/tests']);
$loader->setPsr4('Liberu\\Ecommerce\\Promotions\\', [dirname(__DIR__).'/src']);
$loader->setPsr4('Liberu\\Ecommerce\\Promotions\\Tests\\', [dirname(__DIR__).'/tests']);
return $loader;
```

`vendor/`, and the two local-only config files below, are gitignored.

```bash
vendor/bin/pest                       # everything, including the boundary suite
vendor/bin/pest --testsuite Unit      # this package's own tests
vendor/bin/pest --coverage --min=80
```

**CI is the authority.** Green here is a fast signal, not a result.

### Static analysis

CI runs `vendor/liberusoftware/package-testbench/phpstan.neon`, whose relative
`includes` resolve through a real vendor tree. Locally, package-testbench is a
symlink to a checkout, so those paths do not resolve and a local `phpstan.neon`
spells them out instead. It is gitignored: **the package ships no `phpstan.neon`
and no `pint.json`**, because the shared ones are what CI uses and 60 committed
copies is how they drift.

```bash
vendor/bin/phpstan analyse -c phpstan.neon -l 1 --memory-limit=1G src
```

### Formatting

Pint runs from the release phar, against the shared ruleset:

```bash
curl -fsSL https://github.com/laravel/pint/releases/latest/download/pint.phar -o pint.phar
php pint.phar --test --config /home/tom/code/package-testbench/pint.json
```

The stock `laravel` preset is **not** what CI checks — the shared ruleset adds
`simplified_null_return`, turns `array_indentation` off, and forces `new Foo()`
**with** parentheses even for a no-argument constructor.

## CI

Three thin callers delegating to the shared reusable workflows. The shared
filenames are **`package-`-prefixed**, and a wrong name 404s the whole run — it
does not fail a job, it fails to start one.

| workflow | trigger | delegates to |
|---|---|---|
| `Tests` | push to `main`, pull request | `package-tests.yml` (`phpstan-level: 1`, `coverage-threshold: 80`) |
| `Install` | tags matching `[0-9]+.[0-9]+.[0-9]+` | `package-install.yml` |
| `Compatibility` | tags matching `[0-9]+.[0-9]+.[0-9]+` | `package-compatibility.yml` |

`Compatibility` runs the suite at both ends of every constraint. The
`--prefer-lowest` leg is the one that catches a constraint nothing exercises — a
version range is a claim about every version in it, and only the low end tests
the bottom.

Polling, because chained `sleep N; cmd` beyond one step is blocked here:

```bash
REPO=liberusoftware/module-ecommerce-promotions
R=$(gh run list --repo $REPO --limit 1 --json databaseId --jq '.[0].databaseId')
until [ "$(gh run view $R --repo $REPO --json status --jq .status)" = "completed" ]; do sleep 20; done
gh api repos/$REPO/actions/runs/$R/jobs --jq '.jobs[] | {conclusion, failed: [.steps[]|select(.conclusion=="failure")|.name]}'
```

Newest run per workflow:

```bash
gh run list --repo $REPO --limit 8 --json workflowName,headBranch,conclusion \
  --jq '[.[]|select(.conclusion!=null)]|group_by(.workflowName)|map(.[0]|"\(.workflowName)@\(.headBranch)=\(.conclusion)")|join(" ")'
```

## Operational questions this package can answer

### "Why has our VIP offer applied to nobody all week?"

Quote a representative basket and read the skipped list. A reason of
`eligibility_unresolvable` means the host has not bound
`ResolvesCustomerEligibility` and every offer naming a group has been refused —
which is deliberate, and deliberately distinguishable from `customer_not_eligible`.

```php
$entitlement = app(QuoteBasket::class)($tenantId, $basket, $codes);
collect($entitlement->skipped)->map->toArray();
```

### "Who paused the Black Friday sale, and when?"

```php
app(ListOfferHistory::class)->statusDecisions($offerId);
```

Each row carries `from_status`, `to_status`, a closed-enum `reason`, the
`actor_ref` and `occurred_at`.

### "Is the usage counter right?"

```php
$recompute = app(RecomputeRedemptionsUsed::class);
$recompute->agrees($offer);   // false means the cache has drifted
$recompute($offer->id);       // the ledger's answer
```

The counter exists because a conditional update is the only race-free way to
enforce a limit. If it has drifted, the ledger is the truth: set
`redemptions_used` to the recomputed value. Investigate before doing so — the
only writers are `ClaimRedemption` and `ReleaseRedemption`, so a drift means
something wrote the column directly.

The same applies to the status column via `RecomputeOfferStatus`.

### "This shopper was charged the wrong amount"

Every redemption names the revision it was evaluated under:

```php
$redemption->revision->terms;            // the terms as they were, not as they are
$redemption->revision->revision_number;
$redemption->lines;                      // where each penny came off
```

An edit to an offer changes what happens next and nothing that already happened,
and this is how that is checked rather than asserted.

### "An order was cancelled — give the use back"

```php
foreach (app(ListRedemptionsForOrder::class)($tenantId, $orderRef) as $redemption) {
    app(ReleaseRedemption::class)($redemption->id, ReleaseReason::OrderCancelled, $actorRef);
}
```

Releasing twice raises `RedemptionAlreadyReleased`; the unique index is what
guarantees it, not the check.

### "A shopper has asked to be erased"

```php
app(RedactCustomerFromRedemptions::class)($tenantId, $customerRef);
```

The redemptions, their lines and their releases survive; only the reference is
cleared. The merchant's total usage counters do not move.
