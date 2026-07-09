<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductModelPart;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Admin multi-part model endpoints (AdminCatalogueController): stream, attach and
 * remove the individual parts of a multi-part MODEL_3D product. Staff-only and
 * scoped to the parent product.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    Storage::fake('local');
});

function makePart(Product $product, array $attrs = []): ProductModelPart
{
    return $product->modelParts()->create(array_merge([
        'label' => 'Head',
        'file_ref' => 'models3d/x-1-part1.stl',
        'triangle_count' => 100,
        'is_primary' => false,
        'sort' => 0,
    ], $attrs));
}

function buyerUser(): User
{
    return User::factory()->create(['company_id' => test()->company->id, 'role' => 'buyer']);
}

it('streams a part STL to staff', function (): void {
    Storage::disk('local')->put('models3d/x-1-part1.stl', 'STLBYTES');
    $part = makePart($this->product);

    Sanctum::actingAs($this->staff);
    $res = $this->get("/api/admin/products/{$this->product->id}/parts/{$part->id}/model")->assertOk();

    expect($res->streamedContent())->toBe('STLBYTES');
});

it('forbids a buyer from streaming a part', function (): void {
    Storage::disk('local')->put('models3d/x-1-part1.stl', 'STLBYTES');
    $part = makePart($this->product);

    Sanctum::actingAs(buyerUser());
    $this->get("/api/admin/products/{$this->product->id}/parts/{$part->id}/model")->assertForbidden();
});

it('404s when the part belongs to a different product', function (): void {
    Storage::disk('local')->put('models3d/x-1-part1.stl', 'STLBYTES');
    $other = Product::factory()->create(['class' => 'MODEL_3D']);
    $part = makePart($other);

    Sanctum::actingAs($this->staff);
    // Part id valid, but scoped to the wrong product → 404, no file leak.
    $this->get("/api/admin/products/{$this->product->id}/parts/{$part->id}/model")->assertNotFound();
});

it('404s when the part file is missing from disk', function (): void {
    $part = makePart($this->product); // row exists, file never written

    Sanctum::actingAs($this->staff);
    $this->get("/api/admin/products/{$this->product->id}/parts/{$part->id}/model")->assertNotFound();
});

it('lets staff attach an STL part', function (): void {
    Sanctum::actingAs($this->staff);
    $file = UploadedFile::fake()->createWithContent('leftarm.stl', 'STLBYTES');

    $this->post("/api/admin/products/{$this->product->id}/parts", ['file' => $file, 'label' => 'Left arm'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Left arm')
        ->assertJsonPath('data.is_primary', false);

    expect($this->product->modelParts()->count())->toBe(1);
});

it('accepts a .3mf or .obj part', function (): void {
    Sanctum::actingAs($this->staff);
    $file = UploadedFile::fake()->createWithContent('arm.obj', 'OBJBYTES');

    $this->post("/api/admin/products/{$this->product->id}/parts", ['file' => $file])->assertCreated();
    expect($this->product->modelParts()->first()->file_ref)->toEndWith('.obj');
});

it('rejects a non-mesh part upload', function (): void {
    Sanctum::actingAs($this->staff);
    $file = UploadedFile::fake()->createWithContent('render.png', 'PNGBYTES');

    $this->post("/api/admin/products/{$this->product->id}/parts", ['file' => $file])->assertStatus(422);
    expect($this->product->modelParts()->count())->toBe(0);
});

it('forbids a buyer from uploading a part', function (): void {
    Sanctum::actingAs(buyerUser());
    $file = UploadedFile::fake()->createWithContent('p.stl', 'X');

    $this->post("/api/admin/products/{$this->product->id}/parts", ['file' => $file])->assertForbidden();
});

it('lets staff delete a non-primary part and removes its file', function (): void {
    Storage::disk('local')->put('models3d/x-1-part1.stl', 'STLBYTES');
    $part = makePart($this->product);

    Sanctum::actingAs($this->staff);
    $this->delete("/api/admin/products/{$this->product->id}/parts/{$part->id}")->assertOk();

    expect(ProductModelPart::find($part->id))->toBeNull();
    Storage::disk('local')->assertMissing('models3d/x-1-part1.stl');
});

it('refuses to delete the primary part', function (): void {
    $part = makePart($this->product, ['is_primary' => true]);

    Sanctum::actingAs($this->staff);
    $this->delete("/api/admin/products/{$this->product->id}/parts/{$part->id}")->assertStatus(422);

    expect(ProductModelPart::find($part->id))->not->toBeNull();
});

it('forbids a buyer from deleting a part', function (): void {
    $part = makePart($this->product);

    Sanctum::actingAs(buyerUser());
    $this->delete("/api/admin/products/{$this->product->id}/parts/{$part->id}")->assertForbidden();
    expect(ProductModelPart::find($part->id))->not->toBeNull();
});
