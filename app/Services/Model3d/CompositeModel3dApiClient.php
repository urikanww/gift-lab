<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\Model3dSource;
use App\Services\Model3d\Contracts\Model3dApiClient;

/**
 * Tries each configured client in order and returns the first hit. Lets the
 * live Thingiverse client handle its source while other sources (Cults3D,
 * owned) fall through to the stub until their clients are provisioned.
 */
final class CompositeModel3dApiClient implements Model3dApiClient
{
    /**
     * @param  array<int, Model3dApiClient>  $clients
     */
    public function __construct(private readonly array $clients)
    {
    }

    public function fetch(Model3dSource $source, string $sourceId): ?Model3dData
    {
        foreach ($this->clients as $client) {
            $result = $client->fetch($source, $sourceId);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
