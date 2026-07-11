<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copy existing MODEL_3D files from the private `local` disk into the private
 * `spaces_models` DO Space, keeping every ref (models3d/...) byte-identical so
 * product.model_file_ref / production_file_ref resolve unchanged after the disk
 * flip (MODEL3D_DISK=spaces_models). The reverse of "serving reads config disk":
 * this seeds the S3 disk so serving finds the files once flipped.
 *
 * Idempotent: an object that already exists on the target with the same size is
 * skipped, so re-running is cheap and safe.
 *
 *   php artisan catalogue:migrate-models-to-s3            # copy local -> spaces_models
 *   php artisan catalogue:migrate-models-to-s3 --dry-run
 */
class MigrateModelsToSpaces extends Command
{
    protected $signature = 'catalogue:migrate-models-to-s3
        {--from=local : Source disk holding the models3d/* files}
        {--to=spaces_models : Target private S3 disk}
        {--dry-run : List what would be copied without writing}';

    protected $description = 'Copy MODEL_3D files (models3d/*) from local storage to the private spaces_models Space, keeping refs identical.';

    public function handle(): int
    {
        $fromName = (string) $this->option('from');
        $toName = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && (string) config("filesystems.disks.{$toName}.key") === '') {
            $this->error("AWS credentials for the '{$toName}' disk are not set - fill them in .env first.");

            return self::FAILURE;
        }

        $from = Storage::disk($fromName);
        $to = Storage::disk($toName);

        if (! $dryRun) {
            // Connectivity + scope probe inside the rooted folder.
            $probe = '.giftlab-models-probe';
            $to->put($probe, 'ok');
            if ($to->get($probe) !== 'ok') {
                $this->error('Target probe failed - check credentials/bucket/endpoint.');

                return self::FAILURE;
            }
            $to->delete($probe);
        }

        $copied = 0;
        $skipped = 0;

        foreach ($from->allFiles('models3d') as $path) {
            // Skip when the target already has an identical-size object.
            if (! $dryRun && $to->exists($path) && $to->size($path) === $from->size($path)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  would copy {$path}");
                $copied++;

                continue;
            }

            $to->put($path, (string) $from->get($path));
            $this->info("  copied {$path}");
            $copied++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Copied {$copied}, skipped {$skipped}.");
        if (! $dryRun && $copied > 0) {
            $this->line("Reminder: set MODEL3D_DISK={$toName} and MODEL3D_PRODUCTION_DISK={$toName} so serving reads from the Space.");
        }

        return self::SUCCESS;
    }
}
