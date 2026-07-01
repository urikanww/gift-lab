<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('stores an uploaded artwork image and returns a ref', function (): void {
    $response = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('logo.png', 400, 400),
    ]);

    $response->assertCreated()->assertJsonStructure(['ref', 'url']);
    Storage::disk('local')->assertExists($response->json('ref'));
});

it('rejects a non-image upload', function (): void {
    $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
    ])->assertStatus(422);
});
