<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Push all catalogue product images from local public storage into the
 * DigitalOcean Space and repoint product.image_url at the Space URL.
 *
 * The s3 disk is root-scoped to the DO_STORAGE_FOLDER folder (GIFT_LAB) in
 * config/filesystems.php, so every object this command writes lands inside
 * that folder — it cannot touch anything outside it.
 *
 * Requires AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY in .env. Idempotent:
 * re-running overwrites the same object keys and rewrites the same URLs.
 *
 *   php artisan assets:migrate-to-spaces          # upload + rewrite URLs
 *   php artisan assets:migrate-to-spaces --dry-run
 */
class MigrateAssetsToSpaces extends Command
{
    protected $signature = 'assets:migrate-to-spaces {--dry-run : List what would be uploaded without writing}';

    protected $description = 'Upload catalogue images to the GIFT_LAB folder on DigitalOcean Spaces and repoint product URLs.';

    public function handle(): int
    {
        if (! $this->option('dry-run') && (string) config('filesystems.disks.s3.key') === '') {
            $this->error('AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY are not set — fill them in .env first.');

            return self::FAILURE;
        }

        $spaces = Storage::disk('s3');
        $local = Storage::disk('public');

        if (! $this->option('dry-run')) {
            // Connectivity + scope probe: write/delete a marker INSIDE the
            // rooted folder before touching real assets.
            $probe = '.giftlab-write-probe';
            $spaces->put($probe, 'ok');
            if ($spaces->get($probe) !== 'ok') {
                $this->error('Spaces probe failed — check credentials/bucket/endpoint.');

                return self::FAILURE;
            }
            $spaces->delete($probe);
        }

        $migrated = 0;
        $skipped = 0;

        Product::query()
            ->whereNotNull('image_url')
            ->chunkById(100, function ($products) use ($spaces, $local, &$migrated, &$skipped): void {
                foreach ($products as $product) {
                    $url = (string) $product->image_url;

                    // Only migrate images we serve from local public storage.
                    if (! Str::contains($url, '/storage/products/')) {
                        $skipped++;

                        continue;
                    }

                    $path = 'products/'.basename(parse_url($url, PHP_URL_PATH) ?: '');
                    if (! $local->exists($path)) {
                        $this->warn("  missing local file for product {$product->id}: {$path}");
                        $skipped++;

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("  would upload {$path} → ".config('filesystems.disks.s3.root')."/{$path}");
                        $migrated++;

                        continue;
                    }

                    // Public visibility: these are storefront catalogue images.
                    $spaces->put($path, (string) $local->get($path), 'public');
                    $product->image_url = $spaces->url($path);
                    $product->save();

                    $this->info("  uploaded {$path} → {$product->image_url}");
                    $migrated++;
                }
            });

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Migrated {$migrated}, skipped {$skipped}.");
        if (! $this->option('dry-run') && $migrated > 0) {
            $this->line('Reminder: set FILESYSTEM_DISK=s3 so artwork/proof uploads also land in the GIFT_LAB folder.');
        }

        return self::SUCCESS;
    }
}
