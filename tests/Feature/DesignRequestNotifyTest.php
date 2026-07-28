<?php

declare(strict_types=1);

use App\Events\DesignRequested;
use App\Mail\DesignRequestedMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// When a buyer checks out a "Upload finished look" line (customization.mode ===
// 'buyer_uploaded'), staff must be told a human owes them artwork before the
// proof loop can run. Mirrors the proof-changes-requested alert: staff.queue
// push + an email to every operator.

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $this->product->id]);

    // reference_refs must resolve to a real file on the artwork disk (the
    // FormRequest guards format AND existence), so fake it and seed one.
    $this->disk = Storage::fake((string) config('filesystems.artwork_disk'));
    $this->disk->put('artwork/ref-look.png', 'x');
});

function buyerUploadedPayload(int $companyId, int $productId): array
{
    return [
        'company_id' => $companyId,
        'line_items' => [
            [
                'product_id' => $productId,
                'variant_id' => null,
                'qty' => 2,
                'customization' => [
                    'mode' => 'buyer_uploaded',
                    'reference_refs' => ['artwork/ref-look.png'],
                    'placement_notes' => 'Logo centred, gold foil.',
                ],
            ],
        ],
        'shipping_address' => [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ],
    ];
}

it('alerts staff (push + email to every operator) on a buyer_uploaded design request', function (): void {
    Mail::fake();
    Event::fake([DesignRequested::class]);

    // Two operators with addresses; the buyer must NOT be a recipient.
    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);
    User::factory()->create(['role' => 'superadmin', 'email' => 'boss@nexgen.test', 'company_id' => null]);

    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/quotes', buyerUploadedPayload($this->company->id, $this->product->id))
        ->assertCreated();

    Event::assertDispatched(DesignRequested::class, function (DesignRequested $e): bool {
        return $e->quote->company_id === $this->company->id
            && count($e->lines) === 1
            && $e->lines[0]['qty'] === 2;
    });
    Mail::assertQueued(DesignRequestedMail::class, 2);
});

it('does NOT alert staff for an ordinary (self-designed or plain) line', function (): void {
    Mail::fake();
    Event::fake([DesignRequested::class]);
    User::factory()->staffAdmin()->create(['email' => 'ops@nexgen.test']);

    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
        ],
        'shipping_address' => [
            'recipient_name' => 'Rachel Tan',
            'phone' => '+6591234567',
            'line1' => '1 Marina Blvd',
            'postal_code' => '018989',
        ],
    ])->assertCreated();

    Event::assertNotDispatched(DesignRequested::class);
    Mail::assertNothingQueued();
});
