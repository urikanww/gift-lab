<?php

declare(strict_types=1);

use App\Models\SavedAddress;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('belongs to a user and stores structured fields', function (): void {
    $user = User::factory()->create();
    $addr = SavedAddress::create([
        'user_id' => $user->id,
        'label' => 'Office',
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'city' => 'Singapore',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    expect($user->fresh()->savedAddresses)->toHaveCount(1)
        ->and($addr->user->id)->toBe($user->id)
        ->and($addr->label)->toBe('Office');
});

it('lets a buyer create and list their own addresses', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/saved-addresses', [
        'label' => 'Office',
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ])->assertCreated()->assertJsonPath('data.label', 'Office');

    $this->getJson('/api/saved-addresses')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('caps saved addresses at three per user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    SavedAddress::factory()->count(3)->create(['user_id' => $user->id]);

    $this->postJson('/api/saved-addresses', [
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ])->assertStatus(422);
});

it('forbids editing another users address', function (): void {
    $owner = User::factory()->create();
    $addr = SavedAddress::factory()->create(['user_id' => $owner->id]);
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/saved-addresses/{$addr->id}", [
        'recipient_name' => 'Hacker',
        'phone' => '+6500000000',
        'line1' => 'Nowhere',
        'postal_code' => '000000',
    ])->assertForbidden();
});

it('lets the owner delete their address', function (): void {
    $user = User::factory()->create();
    $addr = SavedAddress::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->deleteJson("/api/saved-addresses/{$addr->id}")->assertOk();
    expect(SavedAddress::find($addr->id))->toBeNull();
});
