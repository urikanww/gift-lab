<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

// Every inline `throttle:N,1` middleware keys an anonymous request by IP alone
// (sha1(domain|IP)), with no per-route prefix - so before the named-limiter fix
// the public catalogue's 60/min budget and login's 6/min budget shared ONE
// counter. A handful of catalogue/bulk-pricing calls from the marketplace page
// then tripped login's limit of 6 before the buyer typed a single credential.
beforeEach(function (): void {
    RateLimiter::clear('login');
    RateLimiter::clear('catalogue');
});

it('does not spend the login throttle budget on public catalogue reads', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    // More catalogue reads than login's 6/min cap - these live on their own budget.
    for ($i = 0; $i < 8; $i++) {
        $this->getJson('/api/catalogue')->assertOk();
    }

    // Login must still be reachable: catalogue traffic must not have consumed it.
    // Origin header marks the request stateful so Sanctum starts the session the
    // controller regenerates on success (mirrors the SPA calling from the frontend).
    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk();
});
