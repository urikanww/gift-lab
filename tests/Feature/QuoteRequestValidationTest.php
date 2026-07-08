<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Pass 2 hardening of POST /api/quotes: tier-set enforcement (F1/D11),
// artwork_ref path integrity (F2/C15), product↔variant linkage (F3/B9) and
// idempotent submission (F6/A12).

beforeEach(function (): void {
    seedPricing();
    Storage::fake((string) config('filesystems.artwork_disk'));
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
    // CORE products need at least one variant to be quotable (E4 guard).
    $this->productVariant = Variant::factory()->create(['product_id' => $this->product->id]);
});

function quotePayload(array $lineOverrides = [], array $overrides = []): array
{
    return array_merge([
        'company_id' => test()->company->id,
        'line_items' => [
            array_merge([
                'product_id' => test()->product->id,
                'variant_id' => null,
                'qty' => 100,
            ], $lineOverrides),
        ],
    ], $overrides);
}

// D11 (F1): out-of-set logo_size is rejected, not silently priced at zero.
it('rejects an out-of-set logo_size instead of pricing it at zero surcharge', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'XL'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.logo_size');
});

it('accepts a configured tier logo_size', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'L'],
    ]))->assertCreated();
});

// C15 (F2): traversal / foreign-prefix / dangling artwork refs are rejected.
it('rejects a traversal artwork_ref', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'artwork_ref' => '../../../../etc/passwd'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.artwork_ref');
});

it('rejects an artwork_ref outside the artwork/ prefix', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'artwork_ref' => 'models/secret.stl'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.artwork_ref');
});

it('rejects an artwork_ref that does not resolve to an uploaded file', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'artwork_ref' => 'artwork/doesnotexist.png'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.artwork_ref');
});

it('accepts a genuine uploaded artwork_ref', function (): void {
    // Upload through the real public endpoint, then quote with the issued ref.
    $ref = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('logo.png', 400, 400),
    ])->assertCreated()->json('ref');

    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'artwork_ref' => $ref],
    ]))->assertCreated();
});

// print_file_ref (the 3D UV-flattened decal) reaches the print pipeline, so it
// gets the same path/existence guard as artwork_ref.
it('rejects a print_file_ref outside the artwork/ prefix', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'print_file_ref' => 'models/secret.stl'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.print_file_ref');
});

it('rejects a print_file_ref that does not resolve to an uploaded file', function (): void {
    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'print_file_ref' => 'artwork/doesnotexist.png'],
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.customization.print_file_ref');
});

it('accepts a genuine uploaded print_file_ref alongside the proof artwork', function (): void {
    $artwork = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('proof.png', 400, 400),
    ])->assertCreated()->json('ref');
    $printFile = $this->postJson('/api/uploads/artwork', [
        'artwork' => UploadedFile::fake()->image('decal.png', 400, 400),
    ])->assertCreated()->json('ref');

    Sanctum::actingAs($this->buyer);

    $this->postJson('/api/quotes', quotePayload([
        'customization' => ['logo_size' => 'M', 'artwork_ref' => $artwork, 'print_file_ref' => $printFile],
    ]))->assertCreated();
});

// B9 (F3): a variant from another product cannot be attached to a line.
it('rejects a variant that belongs to a different product', function (): void {
    Sanctum::actingAs($this->buyer);
    $other = Product::factory()->create(['publish_state' => 'PUBLISHED']);
    $foreignVariant = Variant::factory()->create(['product_id' => $other->id]);

    $this->postJson('/api/quotes', quotePayload([
        'variant_id' => $foreignVariant->id,
    ]))->assertStatus(422)->assertJsonValidationErrors('line_items.0.variant_id');
});

it('accepts a variant that belongs to the line product', function (): void {
    Sanctum::actingAs($this->buyer);
    $variant = Variant::factory()->create(['product_id' => $this->product->id]);

    $this->postJson('/api/quotes', quotePayload([
        'variant_id' => $variant->id,
    ]))->assertCreated();
});

// A12 (F6): the same cart re-submitted with the same key returns the original
// quote instead of creating a duplicate draft.
it('returns the original quote when the same idempotency key is replayed', function (): void {
    Sanctum::actingAs($this->buyer);
    $payload = quotePayload([], ['idempotency_key' => 'checkout-abc-123']);

    $first = $this->postJson('/api/quotes', $payload)->assertCreated()->json('data.id');
    $second = $this->postJson('/api/quotes', $payload)->assertCreated()->json('data.id');

    expect($second)->toBe($first);
    $this->assertDatabaseCount('quotes', 1);
});

it('scopes idempotency keys per company', function (): void {
    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/quotes', quotePayload([], ['idempotency_key' => 'shared-key']))->assertCreated();

    $otherCompany = Company::factory()->create();
    $otherBuyer = User::factory()->create(['company_id' => $otherCompany->id, 'role' => 'buyer']);
    Sanctum::actingAs($otherBuyer);

    $this->postJson('/api/quotes', quotePayload([], [
        'company_id' => $otherCompany->id,
        'idempotency_key' => 'shared-key',
    ]))->assertCreated();

    $this->assertDatabaseCount('quotes', 2);
});
