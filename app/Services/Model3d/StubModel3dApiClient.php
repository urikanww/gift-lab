<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\Model3dSource;
use App\Services\Model3d\Contracts\Model3dApiClient;

/**
 * Default Model3dApiClient binding until Thingiverse/Cults3D API credentials +
 * developer-terms review are cleared. Serves in-memory fixtures; tests seed
 * deterministic models via with().
 */
final class StubModel3dApiClient implements Model3dApiClient
{
    /** @var array<string, Model3dData> */
    private array $models = [];

    public function with(Model3dData $data): self
    {
        $this->models[$this->key($data->source, $data->sourceId)] = $data;

        return $this;
    }

    public function fetch(Model3dSource $source, string $sourceId): ?Model3dData
    {
        return $this->models[$this->key($source, $sourceId)] ?? null;
    }

    private function key(Model3dSource $source, string $sourceId): string
    {
        return $source->value.':'.$sourceId;
    }
}
