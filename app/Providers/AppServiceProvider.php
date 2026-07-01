<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Model3d\Contracts\Model3dApiClient;
use App\Services\Model3d\StubModel3dApiClient;
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
        $this->app->singleton(Model3dApiClient::class, fn ($app) => $app->make(StubModel3dApiClient::class));
    }

    public function boot(): void
    {
        //
    }
}
