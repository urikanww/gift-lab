<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\License;
use App\Enums\Model3dSource;
use App\Enums\PrintMethod;
use App\Enums\ProductClass;
use App\Enums\PublishState;
use App\Enums\StockMode;
use App\Jobs\EnrichImportedModel3dProduct;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk import of scraped MODEL_3D products from the scraper bundle
 * (scraper/export.mjs output): a products.csv plus a folder of .3mf files.
 *
 * Each row becomes (or updates, keyed on source_product_id) a MODEL_3D Product
 * in publish_state=PENDING. The referenced .3mf is copied from --models into the
 * private `local` disk at models3d/ so model_file_ref resolves. Products import
 * UNPUBLISHED on purpose: dimensions are placeholders and estimates are
 * unverified, so the publish gate holds them until staff review. After import
 * run `catalogue:backfill-3d-dimensions` to fill real dimensions from geometry.
 */
class ImportScrapedProducts extends Command
{
    protected $signature = 'products:import
        {csv : Path to products.csv from the scraper}
        {--models= : Folder holding the .3mf files referenced by the CSV}
        {--dry-run : Parse and report without writing products or copying files}';

    protected $description = 'Import scraped MODEL_3D products from a CSV + model-file folder';

    public function handle(): int
    {
        $csvPath = (string) $this->argument('csv');
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");

            return self::FAILURE;
        }

        $modelsDir = $this->option('models') ? rtrim((string) $this->option('models'), '/\\') : null;
        $dryRun = (bool) $this->option('dry-run');

        $rows = $this->readCsv($csvPath);
        if ($rows === []) {
            $this->error('CSV has no data rows.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $filesCopied = 0;
        $missingFiles = [];

        foreach ($rows as $n => $row) {
            $name = trim($row['name'] ?? '');
            if ($name === '') {
                $this->warn("row {$n}: blank name, skipped");

                continue;
            }

            // Copy the model file into the private local disk (models3d/).
            $fileRef = trim($row['model_file_ref'] ?? '');
            if ($fileRef !== '' && $modelsDir !== null) {
                $basename = basename($fileRef);
                $src = "{$modelsDir}/{$basename}";
                if (is_file($src)) {
                    if (! $dryRun) {
                        Storage::disk((string) config('model3d.disk', 'local'))
                            ->put($fileRef, (string) file_get_contents($src));
                    }
                    $filesCopied++;
                } else {
                    $missingFiles[] = $basename;
                    $fileRef = ''; // don't reference a file we didn't place
                }
            }

            $attributes = [
                'class' => ProductClass::Model3d->value,
                'name' => $name,
                'description' => $row['description'] ?? null,
                'category' => $this->blankToNull($row['category'] ?? ''),
                'base_cost' => $this->num($row['base_cost'] ?? '0'),
                'currency' => $row['currency'] ?: 'SGD',
                'min_order_qty' => (int) ($row['min_order_qty'] ?? 1) ?: 1,
                'dimensions' => [
                    'l' => $this->num($row['dim_l'] ?? '0'),
                    'w' => $this->num($row['dim_w'] ?? '0'),
                    'h' => $this->num($row['dim_h'] ?? '0'),
                    'unit' => 'mm',
                ],
                'weight' => $this->num($row['weight'] ?? '0'),
                'print_method' => $this->enumOr(PrintMethod::class, $row['print_method'] ?? '', PrintMethod::Fdm)->value,
                'stock_mode' => $this->enumOr(StockMode::class, $row['stock_mode'] ?? '', StockMode::MakeToOrder)->value,
                'allow_backorder' => $this->bool($row['allow_backorder'] ?? 'false'),
                'license' => $this->enumOr(License::class, $row['license'] ?? '', License::Blocked)->value,
                'creator_credit' => $this->blankToNull($row['creator_credit'] ?? ''),
                'is_printable' => $this->bool($row['is_printable'] ?? 'true'),
                'publish_state' => $this->enumOr(PublishState::class, $row['publish_state'] ?? '', PublishState::Pending)->value,
                'image_url' => $this->blankToNull($row['image_url'] ?? ''),
                'source_url' => $this->blankToNull($row['source_url'] ?? ''),
                'source_product_id' => $this->blankToNull($row['source_product_id'] ?? ''),
                'model_file_ref' => $fileRef !== '' ? $fileRef : null,
                'filament_material' => $this->blankToNull($row['filament_material'] ?? ''),
                'filament_color' => $this->blankToNull($row['filament_color'] ?? ''),
                'est_grams' => $this->num($row['est_grams'] ?? '0') ?: null,
                'est_print_minutes' => $this->num($row['est_print_minutes'] ?? '0') ?: null,
                // Placeholders + unverified estimates -> publish gate keeps PENDING.
                'estimates_verified' => false,
                'model_preview_verified' => false,
            ];

            $sourceId = $attributes['source_product_id'];
            $existing = $sourceId
                ? Product::query()
                    ->where('class', ProductClass::Model3d->value)
                    ->where('source_product_id', $sourceId)
                    ->first()
                : null;

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry] %s  cost=%s  lic=%s  file=%s',
                    $name,
                    $attributes['base_cost'],
                    $attributes['license'],
                    $attributes['model_file_ref'] ?? '(none)',
                ));
                $existing ? $updated++ : $created++;

                continue;
            }

            if ($existing) {
                $existing->fill($attributes)->save();
                $product = $existing;
                $updated++;
            } else {
                $product = Product::query()->create($attributes);
                $created++;
            }

            // Converge onto the shared enrichment (Model3D row, IP flag, .3mf->STL,
            // thumbnail, dimensions) so this CLI is no longer a bypass of the
            // catalogue pipeline - same queued job the HTTP importer dispatches.
            $source = $this->inferSource((string) ($row['source_url'] ?? ''));
            EnrichImportedModel3dProduct::dispatch($product->id, $source->value);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d created, %d updated, %d model files %s.',
            $dryRun ? 'Dry run' : 'Imported',
            $created,
            $updated,
            $filesCopied,
            $dryRun ? 'would copy' : 'copied',
        ));
        if ($missingFiles !== []) {
            $this->warn(sprintf(
                '%d rows had no matching file in --models (imported without model_file_ref): %s',
                count($missingFiles),
                implode(', ', array_slice($missingFiles, 0, 10)).(count($missingFiles) > 10 ? ' ...' : ''),
            ));
        }
        if (! $dryRun) {
            $this->line('Next: php artisan catalogue:backfill-3d-dimensions  (fill real dimensions from geometry)');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);

            return [];
        }
        $header = array_map(static fn ($h): string => trim((string) $h), $header);

        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? (string) $data[$i] : '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    /** Infer the model source from the row's source_url domain (blank -> OWNED). */
    private function inferSource(string $sourceUrl): Model3dSource
    {
        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'makerworld') => Model3dSource::Makerworld,
            str_contains($host, 'thingiverse') => Model3dSource::Thingiverse,
            str_contains($host, 'cults3d') => Model3dSource::Cults3d,
            default => Model3dSource::Owned,
        };
    }

    private function num(string $v): float
    {
        return is_numeric(trim($v)) ? (float) trim($v) : 0.0;
    }

    private function bool(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'y'], true);
    }

    private function blankToNull(string $v): ?string
    {
        $v = trim($v);

        return $v === '' ? null : $v;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  T  $default
     * @return T
     */
    private function enumOr(string $enum, string $value, \BackedEnum $default): \BackedEnum
    {
        return $enum::tryFrom(trim($value)) ?? $default;
    }
}
