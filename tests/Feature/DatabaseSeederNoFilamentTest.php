<?php

declare(strict_types=1);

use App\Models\Filament;
use Illuminate\Support\Facades\Artisan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('does not seed any filament stock via the root seeder', function (): void {
    Artisan::call('db:seed', ['--force' => true]);

    expect(Filament::query()->count())->toBe(0);
});
