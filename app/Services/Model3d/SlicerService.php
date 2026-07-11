<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Headless slicer pass (PrusaSlicer CLI): slices the stored model file and
 * reads real filament grams + print minutes from the generated G-code, then
 * marks the product's estimates verified - replacing the manual staff click
 * for items the slicer can measure. Config-gated: when no slicer binary is
 * configured (services.slicer.binary) everything silently stays on the
 * manual-verify path.
 *
 * A model the slicer rejects (non-manifold, doesn't fit the bed) is a real
 * signal: the item is flagged not printable and held out of publication.
 */
final class SlicerService
{
    public function __construct(private readonly AssetStore $assets = new AssetStore) {}

    /**
     * Configured when EITHER slicer is available: OrcaSlicer (H2S production
     * path, preferred) or the legacy PrusaSlicer estimate-only binary.
     */
    public function isConfigured(): bool
    {
        return $this->orcaBinary() !== '' || (string) config('services.slicer.binary') !== '';
    }

    private function orcaBinary(): string
    {
        return (string) config('services.slicer.orca_binary', '');
    }

    /** OrcaSlicer supersedes PrusaSlicer when its binary is set. */
    private function usesOrca(): bool
    {
        return $this->orcaBinary() !== '';
    }

    /**
     * Slice the product's model file and persist measured estimates (and, on the
     * OrcaSlicer path, the H2S production file). Returns true when measurements
     * were taken.
     */
    public function measure(Product $product): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // MakerWorld case: an H2S-targeted .3mf is already the production file
        // (downloaded with devModelName=O1S). Never re-slice it - just trust it
        // and, if we can, read estimates from its embedded slice info.
        if ($this->hasH2sProductionFile($product)) {
            return $this->measureExistingProductionFile($product);
        }

        $ref = (string) ($product->model_file_ref ?? '');
        $disk = (string) config('model3d.disk', 'local');
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk($disk)->exists($ref)) {
            return false;
        }

        // The slicer CLI needs a real local input path; on S3 this is a temp copy.
        [$input, $cleanupInput] = ModelFileAccess::localPath($disk, $ref);

        try {
            return $this->usesOrca()
                ? $this->sliceOrca($product, $input)
                : $this->slicePrusa($product, $input, $input.'.gcode');
        } finally {
            $cleanupInput();
        }
    }

    /** True when production_file_ref is already a (H2S) .3mf we must not re-slice. */
    private function hasH2sProductionFile(Product $product): bool
    {
        $ref = (string) ($product->production_file_ref ?? '');

        return $ref !== '' && ! str_starts_with($ref, 'http')
            && str_ends_with(strtolower($ref), '.3mf');
    }

    /**
     * Read estimates from an already-present production .3mf (MakerWorld) without
     * slicing. Best-effort: a .3mf that carries no slice info leaves estimates
     * for manual verification. Never marks the item not-printable (a supported
     * printer's .3mf is print-ready by construction).
     */
    private function measureExistingProductionFile(Product $product): bool
    {
        $disk = (string) config('model3d.production_disk', 'local');
        $ref = (string) $product->production_file_ref;
        if (! Storage::disk($disk)->exists($ref)) {
            return false;
        }

        [$path, $cleanup] = ModelFileAccess::localPath($disk, $ref);
        try {
            $parsed = $this->parseOrca3mf($path);
        } finally {
            $cleanup();
        }

        if ($parsed === null) {
            return false;
        }

        [$grams, $minutes] = $parsed;
        $product->est_grams = $grams;
        $product->est_print_minutes = $minutes;
        $product->is_printable = true;
        $product->estimates_verified = true;
        $product->save();

        return true;
    }

    /**
     * OrcaSlicer path: slice the STL against the Bambu H2S profile, PERSIST the
     * sliced project (.gcode.3mf) as the production file the floor prints, and
     * read grams/minutes from the same pass. One slice -> production file +
     * estimates.
     *
     * NOTE (ops-gated, see plan Phase 3): the CLI flag names below follow
     * OrcaSlicer's console interface; verify them against the installed binary/
     * version on the print server before relying on auto-verification.
     */
    private function sliceOrca(Product $product, string $input): bool
    {
        $profile = (string) config('services.slicer.h2s_profile', '');
        $out = tempnam(sys_get_temp_dir(), 'orca').'.gcode.3mf';

        $cmd = [$this->orcaBinary()];
        if ($profile !== '') {
            $cmd[] = '--load-settings';
            $cmd[] = $profile;
        }
        $cmd[] = '--slice';
        $cmd[] = '0';
        $cmd[] = '--export-3mf';
        $cmd[] = $out;
        $cmd[] = $input;

        $result = Process::timeout((int) config('services.slicer.timeout', 300))->run($cmd);

        try {
            if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
                // A slice failure is a printability signal (non-manifold / off-bed).
                Log::warning('OrcaSlicer rejected model file.', [
                    'product_id' => $product->id,
                    'exit_code' => $result->exitCode(),
                    'stderr' => mb_substr($result->errorOutput(), 0, 500),
                ]);
                $product->is_printable = false;
                $product->save();

                return false;
            }

            // Persist the sliced H2S project as the production file.
            [$source, $sourceId] = $this->assetKey($product);
            $product->production_file_ref = $this->assets->storeProductionFile(
                $source,
                $sourceId,
                (string) file_get_contents($out),
                'gcode.3mf',
            );

            $parsed = $this->parseOrca3mf($out);
        } finally {
            @unlink($out);
        }

        $product->is_printable = true;
        if ($parsed !== null) {
            [$grams, $minutes] = $parsed;
            $product->est_grams = $grams;
            $product->est_print_minutes = $minutes;
            // Real measurement replaces the manual staff verification click.
            $product->estimates_verified = true;
        }
        $product->save();

        return $parsed !== null;
    }

    /**
     * Legacy PrusaSlicer path: estimate-only (no persisted production file - the
     * floor prints the STL directly, production_file_ref stays null -> falls back
     * to model_file_ref). Returns true when estimates were measured.
     */
    private function slicePrusa(Product $product, string $input, string $output): bool
    {
        try {
            $result = Process::timeout((int) config('services.slicer.timeout', 300))->run([
                (string) config('services.slicer.binary'),
                '--export-gcode',
                '--output', $output,
                $input,
            ]);

            if (! $result->successful() || ! is_file($output)) {
                // Slicing failure is a printability signal, not just an error:
                // non-manifold geometry or bed overflow means we cannot produce it.
                Log::warning('Slicer rejected model file.', [
                    'product_id' => $product->id,
                    'exit_code' => $result->exitCode(),
                    'stderr' => mb_substr($result->errorOutput(), 0, 500),
                ]);

                $product->is_printable = false;
                $product->save();

                return false;
            }

            $parsed = $this->parseGcode($output);
        } finally {
            @unlink($output);
        }

        if ($parsed === null) {
            return false;
        }

        [$grams, $minutes] = $parsed;

        $product->est_grams = $grams;
        $product->est_print_minutes = $minutes;
        $product->is_printable = true;
        // Slicer measurement replaces the manual staff verification click.
        $product->estimates_verified = true;
        $product->save();

        return true;
    }

    /**
     * Source + id for the production-file storage ref, preferring the linked
     * Model3D provenance (matches how model files are keyed) and falling back to
     * the product id.
     *
     * @return array{0: string, 1: string}
     */
    private function assetKey(Product $product): array
    {
        $model = $product->model3d;
        $source = $model !== null ? strtolower($model->source->value) : 'model3d';
        $sourceId = $model !== null && $model->source_id !== null
            ? (string) $model->source_id
            : (string) $product->id;

        return [$source, $sourceId];
    }

    /**
     * Read grams + minutes from an OrcaSlicer/Bambu sliced .3mf. The sliced
     * project embeds Metadata/slice_info.config with per-plate weight (grams)
     * and prediction (seconds). Best-effort: returns null when the file carries
     * no readable slice info (older exports / a raw non-sliced .3mf).
     *
     * @return array{0: float, 1: float}|null [grams, minutes]
     */
    private function parseOrca3mf(string $path): ?array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('Metadata/slice_info.config');
        $zip->close();

        if ($xml === false || $xml === '') {
            return null;
        }

        // Sum weight + prediction across plates. Prefer explicit weight/prediction
        // metadata; fall back to per-filament used_g when weight is absent.
        $grams = 0.0;
        if (preg_match_all('/key="weight"\s+value="([\d.]+)"/', $xml, $w)) {
            $grams = array_sum(array_map('floatval', $w[1]));
        }
        if ($grams <= 0 && preg_match_all('/used_g="([\d.]+)"/', $xml, $fg)) {
            $grams = array_sum(array_map('floatval', $fg[1]));
        }

        $seconds = 0.0;
        if (preg_match_all('/key="prediction"\s+value="([\d.]+)"/', $xml, $p)) {
            $seconds = array_sum(array_map('floatval', $p[1]));
        }

        if ($grams <= 0 || $seconds <= 0) {
            return null;
        }

        return [round($grams, 1), round($seconds / 60, 1)];
    }

    /**
     * Read filament use and print time from the G-code footer comments
     * PrusaSlicer emits. Grams come from "(total) filament used [g]" when the
     * profile carries a filament density; the default console profile has
     * density 0 (grams line reads 0.00), so fall back to the volume line
     * ("filament used [cm3]") times a configurable density (PLA ≈ 1.24 g/cm³).
     *
     * @return array{0: float, 1: float}|null [grams, minutes]
     */
    private function parseGcode(string $path): ?array
    {
        // Footer comments live at the end; reading the last 64 KiB avoids
        // loading multi-hundred-MB G-code into memory.
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        fseek($handle, max(0, $size - 65536));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $grams = 0.0;
        if (preg_match('/^; (?:total )?filament used \[g\] = ([\d.]+)/m', $tail, $g)) {
            $grams = (float) $g[1];
        }

        if ($grams <= 0 && preg_match('/^; filament used \[cm3\] = ([\d.]+)/m', $tail, $v)) {
            $density = (float) config('services.slicer.density_g_cm3', 1.24);
            $grams = (float) $v[1] * $density;
        }

        if ($grams <= 0) {
            return null;
        }

        if (! preg_match('/^; estimated printing time \(normal mode\) = (.+)$/m', $tail, $t)) {
            return null;
        }

        $minutes = $this->parseDuration(trim($t[1]));
        if ($minutes === null) {
            return null;
        }

        return [round($grams, 1), round($minutes, 1)];
    }

    /**
     * "1d 2h 34m 56s" → minutes.
     */
    private function parseDuration(string $duration): ?float
    {
        if (! preg_match_all('/(\d+)\s*([dhms])/', $duration, $m, PREG_SET_ORDER) || $m === []) {
            return null;
        }

        $minutes = 0.0;
        foreach ($m as [, $value, $unit]) {
            $minutes += (float) $value * match ($unit) {
                'd' => 1440,
                'h' => 60,
                'm' => 1,
                's' => 1 / 60,
            };
        }

        return $minutes;
    }
}
