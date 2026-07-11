<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use Illuminate\Support\Facades\Storage;

/**
 * Bridges the model disk to CLIs and libraries that need a real local file
 * path. On a local disk `Storage::path()` already returns a usable absolute
 * path; on an S3 disk it does not exist - the bytes must first be pulled to a
 * temp file. This helper hides that difference: it returns an absolute path
 * valid for the current disk plus a cleanup callback the caller runs when done.
 *
 * Usage:
 *   [$path, $cleanup] = ModelFileAccess::localPath($disk, $ref);
 *   try { ...use $path... } finally { $cleanup(); }
 */
final class ModelFileAccess
{
    /**
     * @return array{0: string, 1: callable(): void} [absolute local path, cleanup]
     */
    public static function localPath(string $disk, string $ref): array
    {
        $storage = Storage::disk($disk);

        // Local driver: the file already lives on the filesystem, use it in place.
        $config = (array) config("filesystems.disks.{$disk}", []);
        if (($config['driver'] ?? null) === 'local') {
            return [$storage->path($ref), static function (): void {}];
        }

        // Remote (S3): materialise a temp copy, keeping the original extension so
        // slicer/loader tools that switch on it behave the same as on local.
        $ext = pathinfo($ref, PATHINFO_EXTENSION);
        $tmp = tempnam(sys_get_temp_dir(), 'm3d');
        if ($ext !== '') {
            $tmp .= '.'.$ext;
        }
        file_put_contents($tmp, (string) $storage->get($ref));

        return [$tmp, static function () use ($tmp): void {
            @unlink($tmp);
        }];
    }
}
