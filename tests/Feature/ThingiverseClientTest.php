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

it('blocks a non-commercial licence', function (): void {
    fakeThing('Creative Commons - Attribution - Non-Commercial');
    expect($this->client->fetch(Model3dSource::Thingiverse, '1')->license)->toBe('BLOCKED');
});

it('returns null for a non-Thingiverse source', function (): void {
    expect($this->client->fetch(Model3dSource::Cults3d, '1'))->toBeNull();
});
