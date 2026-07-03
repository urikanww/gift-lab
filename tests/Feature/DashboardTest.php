<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Quote;
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

it('reports pipeline, production, and queue counts', function (): void {
    $company = Company::factory()->create();
    Quote::factory()->count(2)->create(['company_id' => $company->id, 'state' => 'SENT']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'ACCEPTED']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/dashboard')->assertOk();

    expect($res->json('pipeline.SENT'))->toBe(2);
    expect($res->json('pipeline.ACCEPTED'))->toBe(1);
    expect($res->json('production'))->toHaveKeys(['byState', 'wip', 'overdue']);
    expect($res->json('queues'))->toHaveKeys(['proofsPending', 'procurementToReconfirm', 'cataloguePending']);
});

it('includes value-booked only for superadmin', function (): void {
    Sanctum::actingAs($this->staff);
    expect($this->getJson('/api/admin/dashboard')->json('valueBooked'))->toBeNull();

    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['valueBooked' => ['currency', 'amount']]);
});

it('caps activity at 20 newest-first and at-risk at 15', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    for ($i = 0; $i < 25; $i++) {
        AuditLog::create([
            'user_id' => $this->staff->id,
            'auditable_type' => Quote::class,
            'auditable_id' => $quote->id,
            'event' => 'quote.amended',
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($this->staff);
    $activity = $this->getJson('/api/admin/dashboard')->json('activity');

    expect($activity)->toHaveCount(20);
    expect($activity[0]['event'])->toBe('quote.amended');
});
