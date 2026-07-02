<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PricingConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Nightly 3D catalogue discovery: loops the admin-configurable keyword list
 * (pricing_configs catalogue/discovery_keywords) through catalogue:pull-3d so
 * new licence-cleared models flow in without anyone running searches by hand.
 * Ingested items still pass the full publish gate (licence, local file,
 * verified estimates) — discovery only feeds the pipeline, it never bypasses
 * the gates.
 */
class DiscoverModel3dCatalogue extends Command
{
    protected $signature = 'catalogue:discover-3d {--count=5 : Models to ingest per keyword per source}';

    protected $description = 'Pull new 3D models for every configured discovery keyword.';

    public function handle(): int
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
