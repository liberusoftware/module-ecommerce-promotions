<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Promotions;

use Illuminate\Support\ServiceProvider;

/**
 * Loads this package's migrations and binds nothing.
 *
 * It is declared in `module.json` and **not** in `extra.laravel.providers`:
 * Composer installing this package must boot nothing. Enablement is the host's
 * explicit decision, made by naming the module in `MODULES_ENABLED`.
 *
 * Both seams are deliberately unbound. They are constructor arguments with a
 * `null` default, so the container resolves them to null until a host binds them —
 * and an offer that needs one that is absent is refused by name rather than
 * failing the request. See {@see Support\OfferGate}.
 */
class PromotionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
