<?php

declare(strict_types=1);

use App\Enums\OrderMilestone;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('lists every milestone, including ones never configured', function (): void {
    Sanctum::actingAs($this->staff);

    $response = $this->getJson('/api/admin/notification-settings')->assertOk();

    // The enum is the registry: the screen must enumerate it rather than keep
    // its own list that could drift out of step.
    expect($response->json('data'))->toHaveCount(count(OrderMilestone::cases()));
});

// A milestone with no config row is still sending, per its own default. Showing
// it as "off" merely because nothing had been written would misrepresent what
// clients actually receive.
it('reports the effective value, not just what has been stored', function (): void {
    Sanctum::actingAs($this->staff);

    $data = collect($this->getJson('/api/admin/notification-settings')->json('data'));

    expect($data->firstWhere('key', 'committed')['enabled'])->toBeTrue();
    // Line changes ship off: staff make that call personally.
    expect($data->firstWhere('key', 'line_changed')['enabled'])->toBeFalse();
});

it('switches a milestone off and stops the email', function (): void {
    Mail::fake();
    Sanctum::actingAs($this->staff);
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);

    $this->patchJson('/api/admin/notification-settings', [
        'key' => 'committed',
        'enabled' => false,
    ])->assertOk();

    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'INVOICED',
        'created_by' => $buyer->id,
    ]);
    $quote->transitionTo(App\Enums\QuoteState::Confirmed);

    Mail::assertNothingQueued();
});

it('switches a defaulted-off milestone on', function (): void {
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings', [
        'key' => 'line_changed',
        'enabled' => true,
    ])->assertOk();

    $data = collect($this->getJson('/api/admin/notification-settings')->json('data'));
    expect($data->firstWhere('key', 'line_changed')['enabled'])->toBeTrue();
});

// Turning a client-facing email off is the sort of thing someone asks about
// three months later.
it('audits a change to a notification setting', function (): void {
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings', [
        'key' => 'shipped',
        'enabled' => false,
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', ['event' => 'notification_setting.updated']);
});

it('rejects an unknown milestone', function (): void {
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings', [
        'key' => 'not_a_milestone',
        'enabled' => true,
    ])->assertNotFound();
});

it('saves a reminder cadence and sorts it', function (): void {
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings/cadence', [
        'quote_days' => [10, 2, 5, 2],
        'proof_days' => [1, 4],
    ])->assertOk();

    expect(PricingConfig::value('notifications_cadence', 'quote_days'))->toBe([2, 5, 10]);
    expect(PricingConfig::value('notifications_cadence', 'proof_days'))->toBe([1, 4]);
});

// The ladder ends on purpose; an unbounded list would be a way to mail someone
// forever.
it('refuses an unbounded reminder ladder', function (): void {
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings/cadence', [
        'quote_days' => [1, 2, 3, 4, 5, 6],
        'proof_days' => [1],
    ])->assertStatus(422);
});

it('chases on the configured cadence rather than the default', function (): void {
    Mail::fake();
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    Sanctum::actingAs($this->staff);

    $this->patchJson('/api/admin/notification-settings/cadence', [
        'quote_days' => [1],
        'proof_days' => [1],
    ])->assertOk();

    Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'SENT',
        'created_by' => $buyer->id,
        // One day: below the default first rung of 3, above the configured 1.
        'price_snapshot_at' => now()->subDays(1),
    ]);

    $this->artisan('quotes:chase')->assertSuccessful();

    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $mail): bool => $mail->milestone === OrderMilestone::ReminderPrice,
    );
});

it('refuses the settings to a buyer', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    Sanctum::actingAs($buyer);

    $this->getJson('/api/admin/notification-settings')->assertForbidden();
    $this->patchJson('/api/admin/notification-settings', ['key' => 'shipped', 'enabled' => false])
        ->assertForbidden();
});
