<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;

// The staff realtime channel must never grant more than the matching HTTP
// route: staff.queue mirrors permission:production.view (GET /api/production-queue).
// A staff_admin who is 403'd over HTTP must also be denied the websocket channel -
// otherwise data leaks over the realtime transport to a user who cannot see it
// via the API.
//
// routes/channels.php registers each channel's authorizer on the default
// broadcaster (via Broadcast::channel()), which forwards to
// BroadcastManager::driver()->channel(). We pull that same authorizer callback
// back out via reflection and invoke it directly - this exercises the exact
// logic in routes/channels.php without depending on a live Reverb/Pusher
// connection (the test env's BROADCAST_CONNECTION is "null").
function staffChannelCallback(string $name): callable
{
    $broadcaster = app(BroadcastManager::class)->driver();

    $property = new ReflectionProperty($broadcaster, 'channels');
    $property->setAccessible(true);

    $channels = $property->getValue($broadcaster);

    expect($channels)->toHaveKey($name);

    return $channels[$name];
}

it('denies staff.queue to a staff_admin without production.view', function (): void {
    $staff = User::factory()->staffAdmin()->create(['permissions' => ['quotes.view']]);

    expect(staffChannelCallback('staff.queue')($staff))->toBeFalse();
});

it('allows staff.queue to a staff_admin granted production.view', function (): void {
    $staff = User::factory()->staffAdmin()->create(['permissions' => ['production.view']]);

    expect(staffChannelCallback('staff.queue')($staff))->toBeTrue();
});

it('still lets a superadmin onto the staff realtime channel regardless of granular grants', function (): void {
    $superadmin = User::factory()->create(['role' => 'superadmin']);

    expect(staffChannelCallback('staff.queue')($superadmin))->toBeTrue();
});

it('still blocks a buyer from the staff realtime channel', function (): void {
    $buyer = User::factory()->create(['role' => 'buyer']);

    expect(staffChannelCallback('staff.queue')($buyer))->toBeFalse();
});
