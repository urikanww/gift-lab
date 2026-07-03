<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

$logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

it('saves and reads back a company brand kit', function () use ($logo): void {
    Sanctum::actingAs($this->buyer);

    $this->putJson('/api/company/brand-kit', [
        'colors' => ['#112233', '#AABBCC'],
        'logo' => $logo,
    ])
        ->assertOk()
        ->assertJsonPath('has_logo', true)
        ->assertJsonPath('colors', ['#112233', '#AABBCC']);

    $this->getJson('/api/company/brand-kit')
        ->assertOk()
        ->assertJsonPath('logo', $logo)
        ->assertJsonPath('colors', ['#112233', '#AABBCC']);
});

it('keeps the stored logo on a colours-only save', function () use ($logo): void {
    $this->company->update(['brand_logo' => $logo, 'brand_colors' => ['#000000']]);
    Sanctum::actingAs($this->buyer);

    $this->putJson('/api/company/brand-kit', ['colors' => ['#ffffff']])
        ->assertOk()
        ->assertJsonPath('has_logo', true)
        ->assertJsonPath('colors', ['#ffffff']);
});

it('rejects a non-image logo and a malformed colour', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->putJson('/api/company/brand-kit', [
        'colors' => ['not-a-hex'],
        'logo' => 'data:text/html,<script>',
    ])->assertStatus(422)->assertJsonValidationErrors(['colors.0', 'logo']);
});

it('requires authentication', function (): void {
    $this->getJson('/api/company/brand-kit')->assertUnauthorized();
});
