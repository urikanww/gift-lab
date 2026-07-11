<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MODEL_3D asset storage
|--------------------------------------------------------------------------
|
| Single source of truth for WHICH disk backs 3D model files, production
| (print-floor) files, and mirrored thumbnails. Every model-file site reads
| these (never a hard-coded disk name) so switching local <-> S3 is one env
| change and serving is unchanged. See docs/PLAN-catalogue-s3-bambu-production.md.
|
| Prod values: MODEL3D_DISK=spaces_models, MODEL3D_PRODUCTION_DISK=spaces_models,
| MODEL3D_THUMBNAIL_DISK=s3. Dev/test default to the private "local" disk and
| the "public" disk, so nothing changes until the env flips.
|
*/

return [
    // The app file: viewer, dimensions, estimate-slice. Relative refs like
    // models3d/{source}/{id}.stl resolve on this disk.
    'disk' => env('MODEL3D_DISK', 'local'),

    // The file the print floor prints (production_file_ref). Same shape/refs.
    'production_disk' => env('MODEL3D_PRODUCTION_DISK', 'local'),

    // Mirrored product thumbnails (public). products/{source}/{id}.jpg.
    'thumbnail_disk' => env('MODEL3D_THUMBNAIL_DISK', 'public'),
];
