<?php

declare(strict_types=1);

use Liberu\Ecommerce\Promotions\Actions\IssueCode;
use Liberu\Ecommerce\Promotions\Actions\QuoteBasket;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesCustomerEligibility;
use Liberu\Ecommerce\Promotions\Contracts\ResolvesProductGrouping;
use Liberu\Ecommerce\Promotions\Data\OfferTerms;
use Liberu\Ecommerce\Promotions\Enums\OfferTarget;
use Liberu\Ecommerce\Promotions\Enums\OfferType;
use Liberu\Ecommerce\Promotions\Enums\RefusalReason;
use Liberu\Ecommerce\Promotions\Enums\StackingMode;
use Liberu\Ecommerce\Promotions\Exceptions\CodeRefused;

/** @param array<string, list<string>> $memberships groupRef => customer refs */
function customerSeam(array $memberships): ResolvesCustomerEligibility
{
    return new class($memberships) implements ResolvesCustomerEligibility
    {
        /** @param array<string, list<string>> $memberships */
        public function __construct(private array $memberships) {}

        public function isCustomerIn(string $customerRef, string $groupRef): bool
        {
            return in_array($customerRef, $this->memberships[$groupRef] ?? [], true);
        }
    };
}

/** @param array<string, list<string>> $contents collectionRef => product refs */
function groupingSeam(array $contents): ResolvesProductGrouping
{
    return new class($contents) implements ResolvesProductGrouping
    {
        /** @param array<string, list<string>> $contents */
        public function __construct(private array $contents) {}

        public function isProductIn(string $productRef, string $groupRef): bool
        {
            return in_array($productRef, $this->contents[$groupRef] ?? [], true);
        }
    };
}

function segmentedOffer(): OfferTerms
{
    return percentageTerms(2000, ['name' => 'VIP twenty', 'customerGroupRefs' => ['vip']]);
}

function collectionOffer(): OfferTerms
{
    return new OfferTerms(
        name: 'Winter sale',
        type: OfferType::Percentage,
        target: OfferTarget::Collection,
        stacking: StackingMode::Stackable,
        valueBasisPoints: 2500,
        collectionRefs: ['winter'],
    );
}

it('resolves nothing by default: no host has bound either seam', function () {
    expect(app()->bound(ResolvesCustomerEligibility::class))->toBeFalse()
        ->and(app()->bound(ResolvesProductGrouping::class))->toBeFalse();

    // And the container hands a null to the action rather than failing to build
    // it, which is what makes both seams genuinely optional.
    expect(app(QuoteBasket::class))->toBeInstanceOf(QuoteBasket::class);
});

it('refuses a segmented offer when the eligibility seam is unbound', function () {
    $offer = activeOffer(segmentedOffer());

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::EligibilityUnresolvable);
});

it('refuses a collection-targeted offer when the grouping seam is unbound', function () {
    $offer = activeOffer(collectionOffer());

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($entitlement->applied)->toBe([])
        ->and($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::EligibilityUnresolvable);
});

/**
 * The one that matters most. Wave 11's rule — an unbound seam that removes a
 * control fails the request — would 503 this checkout. That is wrong here: the
 * blast radius of an unbound seam is the scope of the thing it controls.
 */
it('fails only the offers that name a segment, and lets every other one through', function () {
    $segmented = activeOffer(segmentedOffer());
    $collection = activeOffer(collectionOffer());
    $ordinary = activeOffer(percentageTerms(1000, ['name' => 'Everyday ten']));

    $entitlement = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));

    expect($entitlement->applied)->toHaveCount(1)
        ->and($entitlement->applied[0]->offerId)->toBe($ordinary->id)
        ->and($entitlement->totalMinor())->toBe(1000)
        ->and($entitlement->skipReasonFor($segmented->id))->toBe(RefusalReason::EligibilityUnresolvable)
        ->and($entitlement->skipReasonFor($collection->id))->toBe(RefusalReason::EligibilityUnresolvable);
});

/**
 * The distinction a merchant needs. A skipped offer that reads as an ordinary
 * non-qualification is exactly the failure this rule exists to prevent: a Black
 * Friday VIP offer that has silently applied to nobody for a week.
 */
it('reports an unresolvable seam as a different outcome from not qualifying', function () {
    $offer = activeOffer(segmentedOffer());

    $unbound = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'));
    $bound = (new QuoteBasket(customerSeam(['vip' => ['someone-else']])))(
        TENANT,
        basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'),
    );

    expect($unbound->skipReasonFor($offer->id))->toBe(RefusalReason::EligibilityUnresolvable)
        ->and($bound->skipReasonFor($offer->id))->toBe(RefusalReason::CustomerNotEligible)
        ->and($unbound->skipReasonFor($offer->id))->not->toBe($bound->skipReasonFor($offer->id));
});

it('applies a segmented offer once the seam can answer for the shopper', function () {
    activeOffer(segmentedOffer());

    $quote = new QuoteBasket(customerSeam(['vip' => ['cus-1']]));

    expect($quote(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'))->totalMinor())->toBe(2000)
        ->and($quote(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-2'))->totalMinor())->toBe(0);
});

it('applies a collection offer only to the lines the grouping seam places in it', function () {
    activeOffer(collectionOffer());

    $entitlement = (new QuoteBasket(null, groupingSeam(['winter' => ['scarf']])))(TENANT, basket([
        ['line-1', 'scarf', 1, 4000],
        ['line-2', 'sunhat', 1, 4000],
    ]));

    expect($entitlement->totalMinor())->toBe(1000)
        ->and($entitlement->allocationForLine('line-1'))->toBe(1000)
        ->and($entitlement->allocationForLine('line-2'))->toBe(0);
});

it('refuses a segmented offer for a basket with no shopper at all', function () {
    $offer = activeOffer(segmentedOffer());

    $entitlement = (new QuoteBasket(customerSeam(['vip' => ['cus-1']])))(TENANT, basket([['line-1', 'p-1', 1, 10000]]));

    expect($entitlement->skipReasonFor($offer->id))->toBe(RefusalReason::CustomerNotEligible);
});

it('tells the shopper the code was refused, in the one message, whichever of these happened', function () {
    $offer = activeOffer(segmentedOffer());
    (new IssueCode())(TENANT, $offer->id, 'VIP20');

    $unresolvable = (new QuoteBasket())(TENANT, basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'), ['VIP20']);
    $notEligible = (new QuoteBasket(customerSeam(['vip' => ['other']])))(
        TENANT,
        basket([['line-1', 'p-1', 1, 10000]], customerRef: 'cus-1'),
        ['VIP20'],
    );

    foreach ([$unresolvable, $notEligible] as $entitlement) {
        $refusal = CodeRefused::because($entitlement->refusedCodes['VIP20']);

        expect($entitlement->honouredCodes)->toBe([])
            ->and($refusal->getMessage())->toBe('That code cannot be applied to this basket.')
            ->and($refusal->errorCode())->toBe('promotions.code_refused')
            ->and($refusal->status())->toBe(422);
    }

    // Same shopper-facing message; different merchant-facing reason.
    expect($unresolvable->refusedCodes['VIP20'])->toBe(RefusalReason::EligibilityUnresolvable)
        ->and($notEligible->refusedCodes['VIP20'])->toBe(RefusalReason::CustomerNotEligible);
});
