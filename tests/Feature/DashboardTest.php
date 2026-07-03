<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->superadmin()->create();
    $company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
});

it('gates the dashboard to staff', function (): void {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/dashboard')->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['pipeline', 'production', 'atRisk', 'queues', 'activity']);
});
