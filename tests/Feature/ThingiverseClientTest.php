<?php

declare(strict_types=1);

use App\Enums\Model3dSource;
use App\Services\Model3d\HttpThingiverseClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.thingiverse.token', 'test-token');
    config()->set('services.thingiverse.base_url', 'https://api.thingiverse.com');
    $this->client = app(HttpThingiverseClient::class);
});

function fakeThing(string $license): void
{
    Http::fake([
        'api.thingiverse.com/things/*' => Http::response([
            'name' => 'Cool Widget',
            'license' => $license,
            'creator' => ['name' => 'Jane Maker'],
            'public_url' => 'https://www.thingiverse.com/thing:123',
        ], 200),
    ]);
}

it('maps a Creative Commons Attribution licence to CC_BY', function (): void {
    fakeThing('Creative Commons - Attribution');
    $data = $this->client->fetch(Model3dSource::Thingiverse, '123');

    expect($data)->not->toBeNull()
        ->and($data->license)->toBe('CC_BY')
        ->and($data->creatorCredit)->toBe('Jane Maker');
});

it('maps public domain to CC0', function (): void {
    fakeThing('Creative Commons - Public Domain Dedication');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('CC0');
});

it('maps a non-commercial licence to CC_BY_NC (operator-enabled)', function (): void {
    fakeThing('Creative Commons - Attribution - Non-Commercial');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('CC_BY_NC');
});

it('maps a no-derivatives licence to CC_BY_ND (operator-enabled)', function (): void {
    fakeThing('Creative Commons - Attribution - No Derivatives');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('CC_BY_ND');
});

it('allows a share-alike licence (commercial-OK with attribution)', function (): void {
    fakeThing('Creative Commons - Attribution - Share Alike');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('CC_BY_SA');
});

it('maps NonCommercial-ShareAlike to CC_BY_NC_SA (NC combo, most-specific wins)', function (): void {
    fakeThing('Creative Commons - Attribution - Non-Commercial - Share Alike');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('CC_BY_NC_SA');
});

it('maps a GPL licence', function (): void {
    fakeThing('GNU - GPL');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('GPL');
});

it('maps LGPL to LGPL, not swallowed by the GPL substring', function (): void {
    fakeThing('GNU - LGPL');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('LGPL');
});

it('allows a BSD licence', function (): void {
    fakeThing('BSD License');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('BSD');
});

it('blocks an unknown licence label', function (): void {
    fakeThing('All Rights Reserved');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('BLOCKED');
});

it('returns null for a non-Thingiverse source', function (): void {
    expect($this->client->fetch(Model3dSource::Cults3d, '1'))->toBeNull();
});
