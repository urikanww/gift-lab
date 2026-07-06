<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PricingConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Nightly 3D catalogue discovery. Default sweep (Phase 2): a single
 * keyword-less "popular browse" pull per source via catalogue:pull-3d
 * --browse=popular, capped by pricing_configs catalogue/browse_cap. This
 * removes the dependency on a hand-maintained keyword list for the nightly
 * sweep. The legacy per-keyword search loop (pricing_configs
 * catalogue/discovery_keywords) is kept as an opt-in fallback via
 * --keywords, for sources/cases where a targeted search still helps.
 * Ingested items still pass the full publish gate (licence, local file,
 * verified estimates) — discovery only feeds the pipeline, it never bypasses
 * the gates.
 */
class DiscoverModel3dCatalogue extends Command
{
    protected $signature = 'catalogue:discover-3d
        {--count=5 : Models to ingest per keyword per source (--keywords mode only)}
        {--keywords : Use the legacy keyword list instead of the default popular-browse sweep}';

    protected $description = 'Nightly 3D catalogue sweep: popular-browse by default, or the legacy keyword list with --keywords.';

    public function handle(): int
    {
        if ($this->option('keywords')) {
            return $this->sweepKeywords();
        }

        return $this->sweepBrowse();
    }

    /**
     * Default sweep (Phase 2): one keyword-less popular-browse pull per
     * source, capped by catalogue/browse_cap so a nightly run can't ingest
     * unboundedly many models.
     */
    private function sweepBrowse(): int
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

    /**
     * Legacy fallback: loops the admin-configurable keyword list
     * (pricing_configs catalogue/discovery_keywords) through
     * catalogue:pull-3d, one search per keyword per source.
     */
    private function sweepKeywords(): int
    {
        $keywords = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) PricingConfig::value('catalogue', 'discovery_keywords', []),
        ), static fn (string $k): bool => $k !== ''));

        if ($keywords === []) {
            $this->warn('No discovery keywords configured (pricing_configs catalogue/discovery_keywords) — nothing to do.');

            return self::SUCCESS;
        }

        $count = max(1, (int) $this->option('count'));

        foreach ($keywords as $keyword) {
            $this->info("Discovering \"{$keyword}\"…");

            // Per-keyword isolation: a failing source/keyword must not stop
            // the rest of the sweep. pull-3d reports its own failures.
            try {
                Artisan::call('catalogue:pull-3d', ['query' => $keyword, '--count' => $count], $this->output);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Keyword \"{$keyword}\" failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
