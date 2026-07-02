<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Model3d\CompositeModel3dApiClient;
use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\HttpCults3dClient;
use App\Services\Model3d\HttpThingiverseClient;
use App\Services\Model3d\StubModel3dApiClient;
use App\Services\Payment\Contracts\PaymentGateway;
use App\Services\Payment\FixturePaymentGateway;
use App\Services\Payment\StripePaymentGateway;
use App\Services\Procurement\Contracts\MarketplaceRechecker;
use App\Services\Procurement\FixtureMarketplaceRechecker;
use App\Models\Quote;
use App\Policies\QuotePolicy;
use App\Services\Scraper\CompositeScraperClient;
use App\Services\Scraper\Contracts\ScraperClient;
use App\Services\Scraper\FixtureScraperClient;
use App\Services\Scraper\HttpLazadaAffiliateClient;
use App\Services\Scraper\HttpShopeeAffiliateClient;
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
        $this->app->singleton(ScraperClient::class, function ($app) {
            // Route per marketplace: source ids prefixed "lazada:" go to the
            // Lazada client, everything else to Shopee. Each feed activates
            // only when its credentials are provisioned; with neither set the
            // fixture serves everything (tests/dev).
            $shopee = config('services.shopee_affiliate.app_id') && config('services.shopee_affiliate.secret')
                ? $app->make(HttpShopeeAffiliateClient::class)
                : null;

            $lazada = config('services.lazada_affiliate.app_key') && config('services.lazada_affiliate.secret')
                ? $app->make(HttpLazadaAffiliateClient::class)
                : null;

            if ($shopee === null && $lazada === null) {
                return $app->make(FixtureScraperClient::class);
            }

            return new CompositeScraperClient($shopee, $lazada, $app->make(FixtureScraperClient::class));
        });

        $this->app->singleton(FixtureMarketplaceRechecker::class);
        $this->app->singleton(MarketplaceRechecker::class, fn ($app) => $app->make(FixtureMarketplaceRechecker::class));

        $this->app->singleton(StubModel3dApiClient::class);
        $this->app->singleton(Model3dApiClient::class, function ($app) {
            // Route each source with credentials to its live client; the stub
            // stays last so uncredentialed sources fall through to fixtures.
            $clients = [];

            if (config('services.thingiverse.token')) {
                $clients[] = $app->make(HttpThingiverseClient::class);
            }

            if (config('services.cults3d.username') && config('services.cults3d.token')) {
                $clients[] = $app->make(HttpCults3dClient::class);
            }

            if ($clients === []) {
                return $app->make(StubModel3dApiClient::class);
            }

            $clients[] = $app->make(StubModel3dApiClient::class);

            return new CompositeModel3dApiClient($clients);
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
