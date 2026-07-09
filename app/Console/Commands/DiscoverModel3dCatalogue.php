<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Nightly 3D catalogue discovery. A single keyword-less "popular browse" pull
 * from Thingiverse via catalogue:pull-3d --browse=popular. Cults3D pulling is
 * disabled (owner decision) and the run is uncapped - it pages the popular feed
 * up to pull-3d's MAX_BROWSE_PAGES ceiling rather than stopping at a fixed
 * browse_cap. Ingested items still pass the full publish gate (local file,
 * verified estimates, licence review) - discovery only feeds the pipeline, it
 * never bypasses the gates.
 */
class DiscoverModel3dCatalogue extends Command
{
    /**
     * Uncapped target - a value large enough that pull-3d's MAX_BROWSE_PAGES
     * page ceiling is the only bound, so the sweep never stops early on a
     * browse_cap.
     */
    private const UNCAPPED_TARGET = 100000;

    protected $signature = 'catalogue:discover-3d';

    protected $description = 'Nightly 3D catalogue sweep: uncapped Thingiverse popular-browse (Cults3D disabled).';

    public function handle(): int
    {
        $this->info('Browsing the Thingiverse popular feed (Cults3D disabled, uncapped)…');

        try {
            Artisan::call('catalogue:pull-3d', [
                '--browse' => 'popular',
                '--source' => 'thingiverse',
                '--count' => self::UNCAPPED_TARGET,
            ], $this->output);
        } catch (\Throwable $e) {
            report($e);
            $this->error("Popular-browse sweep failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
