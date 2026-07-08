<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LineItem;
use App\Models\ProductionJob;
use App\Models\Proof;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes anonymous designer artwork (uploaded via the public
 * POST /uploads/artwork surface) that no quote/proof/job ever referenced and is
 * older than --days. The public upload is account-free, so a buyer who abandons
 * the designer before Requesting a Quote leaves an orphan behind; without this
 * sweep those files accumulate forever on the private artwork disk (P2-2).
 *
 * "Referenced" = the storage key appears in ANY of:
 *   - line_items.customization->artwork_ref    (the line's captured artwork)
 *   - line_items.customization->print_file_ref (the 3D UV-flattened decal)
 *   - proofs.artwork_version_ref               (a formal proof version)
 *   - production_jobs.artwork_ref              (the approved print file)
 * so a still-live design at any stage of the funnel is never pruned. Only files
 * older than the grace window (--days, default 7) are eligible, so an upload
 * mid-checkout (ref not yet persisted) is safe.
 *
 *   php artisan artwork:prune-orphans
 *   php artisan artwork:prune-orphans --days=14 --dry-run
 */
class PruneOrphanArtwork extends Command
{
    protected $signature = 'artwork:prune-orphans '
        .'{--days=7 : Minimum age in days before an unreferenced upload is eligible} '
        .'{--dry-run : List what would be deleted without removing anything}';

    protected $description = 'Delete anonymous artwork uploads not referenced by any quote/proof/job and older than N days.';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('filesystems.artwork_disk'));
        $cutoff = now()->subDays(max(0, (int) $this->option('days')))->getTimestamp();
        $referenced = $this->referencedKeys();

        $deleted = 0;
        $kept = 0;

        foreach ($disk->allFiles('artwork') as $path) {
            if (isset($referenced[$path])) {
                $kept++;

                continue;
            }

            // Grace window: skip anything newer than the cutoff so an upload
            // still moving through checkout (ref not yet saved) is never pruned.
            if ($disk->lastModified($path) > $cutoff) {
                $kept++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would delete {$path}");
                $deleted++;

                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')
            ."Pruned {$deleted} orphan artwork file(s); kept {$kept} referenced/recent.");

        return self::SUCCESS;
    }

    /**
     * Every artwork storage key currently referenced by a quote line, proof, or
     * production job, keyed for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function referencedKeys(): array
    {
        $keys = [];

        // Line items store the ref inside the customization JSON blob, so it
        // can't be plucked in SQL portably (SQLite/MySQL JSON differ) - read the
        // cast array. Bounded by line count; runs once per daily sweep.
        LineItem::query()
            ->withTrashed()
            ->whereNotNull('customization')
            ->select(['id', 'customization'])
            ->each(function (LineItem $line) use (&$keys): void {
                // Both the proof artwork and the 3D UV-flattened print file live
                // on the artwork disk and must be treated as in-use, else the
                // decal is pruned out from under a still-live order.
                foreach (['artwork_ref', 'print_file_ref'] as $refKey) {
                    $ref = $line->customization[$refKey] ?? null;
                    if (is_string($ref) && $ref !== '') {
                        $keys[$ref] = true;
                    }
                }
            });

        Proof::query()
            ->withTrashed()
            ->whereNotNull('artwork_version_ref')
            ->pluck('artwork_version_ref')
            ->each(function (?string $ref) use (&$keys): void {
                if (is_string($ref) && $ref !== '') {
                    $keys[$ref] = true;
                }
            });

        ProductionJob::query()
            ->withTrashed()
            ->whereNotNull('artwork_ref')
            ->pluck('artwork_ref')
            ->each(function (?string $ref) use (&$keys): void {
                if (is_string($ref) && $ref !== '') {
                    $keys[$ref] = true;
                }
            });

        return $keys;
    }
}
