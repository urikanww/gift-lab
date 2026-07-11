<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use RuntimeException;

/**
 * Thrown when a `.3mf` cannot be turned into a usable STL - a corrupt/missing
 * archive, an unreadable model part, or a mesh so large it breaches the
 * triangle cap. It is a *typed* failure on purpose: the ingest path prints from
 * our own copy of a model (see {@see Model3dFileStore}), so a caller that hits
 * this can cleanly fall back (store the raw `.3mf`, shell out to a slicer CLI,
 * or flag the item for manual attention) instead of poisoning the STL pipeline
 * with a truncated or bogus mesh.
 */
final class ThreeMfConversionException extends RuntimeException {}
