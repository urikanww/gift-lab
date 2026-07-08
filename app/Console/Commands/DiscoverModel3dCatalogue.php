<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PricingConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Nightly 3D catalogue discovery. A single keyword-less "popular browse" pull
 * per source via catalogue:pull-3d --browse=popular, capped by pricing_configs
 * catalogue/browse_cap. Ingested items still pass the full publish gate
 * (licence, local file, verified estimates) - discovery only feeds the
 * pipeline, it never bypasses the gates.
 */
class DiscoverModel3dCatalogue extends Command
{
    protected $signature = 'catalogue:discover-3d';

    protected $description = 'Nightly 3D catalogue sweep: popular-browse per source, capped by catalogue/browse_cap.';

    public function handle(): int
    {
        $cap = max(1, (int) PricingConfig::value('catalogue', 'browse_cap', 200));

        $this->info("Browsing popular feeds (cap {$cap} per source)…");

        try {
            Artisan::call('catalogue:pull-3d', ['--browse' => 'popular', '--count' => $cap], $this->output);
        } catch (\Throwable $e) {
            report($e);
            $this->error("Popular-browse sweep failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
