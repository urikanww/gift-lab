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
use App\Models\Quote;
use App\Policies\QuotePolicy;
use App\Services\Scraper\Contracts\ScraperClient;
use App\Services\Scraper\FixtureScraperClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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

        // Payments: Stripe when a secret is configured. The FixturePaymentGateway
        // auto-succeeds (marks POs PAID with no real charge), so it MUST NEVER be
        // reachable outside local/testing. In any other environment a missing
        // STRIPE_SECRET is a hard misconfiguration — fail closed (refuse to
        // resolve the gateway) rather than silently issuing free orders.
        $this->app->singleton(PaymentGateway::class, function ($app) {
            if (config('services.stripe.secret')) {
                return $app->make(StripePaymentGateway::class);
            }

            if (! $app->environment(['local', 'testing'])) {
                throw new RuntimeException(
                    'STRIPE_SECRET is not configured. Refusing to fall back to the '
                    .'auto-succeed FixturePaymentGateway in the "'.$app->environment()
                    .'" environment — this would mark orders PAID without a real charge. '
                    .'Set STRIPE_SECRET or disable the B2C pay-now feature.'
                );
            }

            return $app->make(FixturePaymentGateway::class);
        });
    }

    public function boot(): void
    {
        // Central authorization safety net: register QuotePolicy explicitly so
        // Gate::authorize / $this->authorize('...', $quote) enforces tenancy
        // isolation for every quote action, instead of relying on scattered
        // inline abort_unless() checks that a new endpoint could forget.
        Gate::policy(Quote::class, QuotePolicy::class);
    }
}
