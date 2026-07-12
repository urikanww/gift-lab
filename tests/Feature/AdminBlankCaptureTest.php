<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('captures a URL into a draft SCRAPED_UV blank in the gate', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <script type="application/ld+json">
        {"@type":"Product","name":"Blank Mug 440ml","image":"https://cdn.sg/m.jpg",
         "offers":{"price":"12.90","priceCurrency":"SGD"}}
        </script></head></html>
        HTML, 200)]);

    Sanctum::actingAs($this->staff);
    $res = $this->postJson('/api/admin/blank-candidates/capture', [
        'url' => 'https://blankco.sg/mug-440',
    ])->assertOk();

    $id = $res->json('data.id');
    $product = Product::findOrFail($id);

    expect($product->class->value)->toBe('SCRAPED_UV')
        ->and($product->name)->toBe('Blank Mug 440ml')
        ->and($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->source_links)->toHaveCount(1)
        ->and($product->source_links[0]['url'])->toBe('https://blankco.sg/mug-440')
        ->and($product->source_url)->toBe('https://blankco.sg/mug-440');
});

it('rejects a non-url', function (): void {
    Sanctum::actingAs($this->staff);
    $this->postJson('/api/admin/blank-candidates/capture', ['url' => 'not-a-url'])
        ->assertStatus(422);
});

it('returns 502 when the page cannot be captured', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    Sanctum::actingAs($this->staff);
    $this->postJson('/api/admin/blank-candidates/capture', ['url' => 'https://blankco.sg/down'])
        ->assertStatus(502);
});

it('forbids non-staff users', function (): void {
    $buyer = User::factory()->create(['role' => 'buyer']);
    Sanctum::actingAs($buyer);
    $this->postJson('/api/admin/blank-candidates/capture', ['url' => 'https://blankco.sg/x'])
        ->assertStatus(403);
});

it('requires auth', function (): void {
    $this->postJson('/api/admin/blank-candidates/capture', ['url' => 'https://blankco.sg/x'])
        ->assertStatus(401);
});
