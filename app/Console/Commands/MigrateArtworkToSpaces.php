<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copy artwork + proof files from the LOCAL private disk to the configured
 * artwork disk (a DigitalOcean Space in prod). Fixes the transition gap: files
 * uploaded while ARTWORK_DISK was still 'local' live under storage/app/private,
 * but once ARTWORK_DISK points at a Space the viewing links presign the bucket -
 * where those keys don't exist yet (S3 NoSuchKey). This lifts them across so the
 * existing refs resolve, without changing a single stored ref.
 *
 * Keys are preserved exactly (proofs/… , artwork/…), so every Proof.artwork_-
 * version_ref and line customization.artwork_ref keeps working untouched.
 * Idempotent: files already on the target are skipped.
 *
 *   php artisan artwork:migrate-to-spaces
 *   php artisan artwork:migrate-to-spaces --dry-run
 */
class MigrateArtworkToSpaces extends Command
{
    protected $signature = 'artwork:migrate-to-spaces {--dry-run : List what would be copied without writing}';

    protected $description = 'Copy local private artwork/proof files to the configured artwork disk (DigitalOcean Spaces).';

    /** The key prefixes the two upload surfaces write under. */
    private const PREFIXES = ['proofs', 'artwork'];

    public function handle(): int
    {
        $targetName = (string) config('filesystems.artwork_disk', 'local');

        if ($targetName === 'local' || config("filesystems.disks.{$targetName}.driver") !== 's3') {
            $this->error("ARTWORK_DISK ({$targetName}) is not an S3/Spaces disk - nothing to migrate to. Set it to a Spaces disk first.");

            return self::FAILURE;
        }

        $local = Storage::disk('local');
        $target = Storage::disk($targetName);

        if (! $this->option('dry-run')) {
            // Connectivity + scope probe inside the rooted folder.
            $probe = 'proofs/.migrate-probe';
            $target->put($probe, 'ok');
            if ($target->get($probe) !== 'ok') {
                $this->error("Target disk probe failed - check {$targetName} credentials/bucket/endpoint.");

                return self::FAILURE;
            }
            $target->delete($probe);
        }

        $copied = 0;
        $skipped = 0;

        foreach (self::PREFIXES as $prefix) {
            foreach ($local->allFiles($prefix) as $file) {
                if ($target->exists($file)) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would copy {$file} → {$targetName}:{$file}");
                    $copied++;

                    continue;
                }

                // writeStream keeps memory flat for large files; the target disk's
                // default (private) visibility applies, matching the upload path.
                $stream = $local->readStream($file);
                $target->writeStream($file, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $this->info("  copied {$file}");
                $copied++;
            }
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Copied {$copied}, skipped {$skipped} (already present).");

        return self::SUCCESS;
    }
}
