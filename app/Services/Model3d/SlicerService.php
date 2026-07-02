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
 * marks the product's estimates verified — replacing the manual staff click
 * for items the slicer can measure. Config-gated: when no slicer binary is
 * configured (services.slicer.binary) everything silently stays on the
 * manual-verify path.
 *
 * A model the slicer rejects (non-manifold, doesn't fit the bed) is a real
 * signal: the item is flagged not printable and held out of publication.
 */
final class SlicerService
{
    public function isConfigured(): bool
    {
        return (string) config('services.slicer.binary') !== '';
    }

    /**
     * Slice the product's model file and persist measured estimates.
     * Returns true when measurements were taken.
     */
    public function measure(Product $product): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $ref = (string) ($product->model_file_ref ?? '');
        if ($ref === '' || str_starts_with($ref, 'http') || ! Storage::disk('local')->exists($ref)) {
            return false;
        }

        $input = Storage::disk('local')->path($ref);
        $output = Storage::disk('local')->path($ref.'.gcode');

        $result = Process::timeout((int) config('services.slicer.timeout', 300))->run([
            (string) config('services.slicer.binary'),
            '--export-gcode',
            '--load-defaults',
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
        @unlink($output);

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
     * Read "filament used [g]" and "estimated printing time" from the G-code
     * footer comments PrusaSlicer emits.
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

        if (! preg_match('/^; filament used \[g\] = ([\d.]+)/m', $tail, $g)) {
            return null;
        }

        if (! preg_match('/^; estimated printing time \(normal mode\) = (.+)$/m', $tail, $t)) {
            return null;
        }

        $minutes = $this->parseDuration(trim($t[1]));
        if ($minutes === null) {
            return null;
        }

        return [round((float) $g[1], 1), round($minutes, 1)];
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
