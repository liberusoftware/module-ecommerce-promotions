<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\PackageTestbench\PackageTestCase;

/**
 * The package's own case. Everything else comes from the testbench, which boots
 * the provider `module.json` declares.
 *
 * **Neither seam is bound here, in any test.** That is the point: the whole suite
 * runs with `ResolvesCustomerEligibility` and `ResolvesProductGrouping` absent
 * unless a test binds one on purpose, so "this module owns no shopper" is proved
 * by building against absence rather than asserted in a document.
 */
abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;
}
