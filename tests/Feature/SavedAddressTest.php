<?php

declare(strict_types=1);

use App\Models\SavedAddress;
use App\Models\User;

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
