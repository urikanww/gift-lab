<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Model3d\CompositeModel3dApiClient;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\HttpThingiverseClient;
use App\Services\Model3d\StubModel3dApiClient;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\FixturePaymentGateway;
use App\Services\Payment\StripePaymentGateway;
use App\Services\Procurement\Contracts\MarketplaceRechecker;
use App\Services\Procurement\FixtureMarketplaceRechecker;
use App\Services\Scraper\Contracts\ScraperClient;
use App\Services\Scraper\FixtureScraperClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase-2 external integrations are bound to fixtures/stubs (Phase 0
        // decision): live Shopee/Lazada ingest, marketplace re-check, and the
        // Thingiverse/Cults3D API clients are provisioned separately and swapped
        // in here. Singletons so tests can seed deterministic data on the same
        // instance the services resolve.
        $this->app->singleton(FixtureScraperClient::class);
        $this->app->singleton(ScraperClient::class, fn ($app) => $app->make(FixtureScraperClient::class));

        $this->app->singleton(FixtureMarketplaceRechecker::class);
        $this->app->singleton(MarketplaceRechecker::class, fn ($app) => $app->make(FixtureMarketplaceRechecker::class));

        $this->app->singleton(StubModel3dApiClient::class);
        $this->app->singleton(Model3dApiClient::class, function ($app) {
            // With a Thingiverse token, route THINGIVERSE to the live client and
            // fall through to the stub for other sources; otherwise all stub.
            if (config('services.thingiverse.token')) {
                return new CompositeModel3dApiClient([
                    $app->make(HttpThingiverseClient::class),
                    $app->make(StubModel3dApiClient::class),
                ]);
            }

            return $app->make(StubModel3dApiClient::class);
        });

        // Payments: Stripe when a secret is configured, otherwise a fixture
        // gateway that auto-succeeds (keeps the pay-now flow exercisable in dev/test).
        $this->app->singleton(PaymentGateway::class, function ($app) {
            return config('services.stripe.secret')
                ? $app->make(StripePaymentGateway::class)
                : $app->make(FixturePaymentGateway::class);
        });
    }

    public function boot(): void
    {
        //
    }
}
