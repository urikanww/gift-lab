<?php

declare(strict_types=1);

use App\Models\Model3D;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

// CSV product import (superadmin): validate-before-insert, idempotent upsert,
// per-row error reporting, and the security guard on model_file_ref.

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
});

const IMPORT_HEADER = 'name,class,category,description,base_cost,currency,min_order_qty,'
    .'dim_l,dim_w,dim_h,weight,print_method,stock_mode,allow_backorder,license,creator_credit,'
    .'is_printable,publish_state,image_url,source_url,source_product_id,model_file_ref,'
    .'filament_material,filament_color,est_grams,est_print_minutes';

function importCsv(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('products.csv', $body);
}

it('lets a superadmin import valid rows as PENDING MODEL_3D products', function (): void {
    Sanctum::actingAs($this->superadmin);

    $csv = IMPORT_HEADER."\n"
        .'Mystic Dragon,MODEL_3D,characters,A dragon,15.39,SGD,1,100,100,100,233,FDM,MAKE_TO_ORDER,'
        .'false,OWNED,DElex3D,true,PENDING,https://cdn.example.com/a.png,https://makerworld.com/en/models/3015782,'
        ."3015782,models3d/mystic-dragon-3015782.3mf,PLA,#FFFFFF,233,840\n";

    $res = $this->postJson('/api/admin/products/import', ['file' => importCsv($csv)])->assertOk();

    $res->assertJsonPath('data.created', 1);
    $res->assertJsonPath('data.skipped', 0);

    $p = Product::where('source_product_id', '3015782')->firstOrFail();
    expect($p->class->value)->toBe('MODEL_3D');
    expect($p->publish_state->value)->toBe('PENDING');
    expect($p->license->value)->toBe('OWNED');
    expect((float) $p->base_cost)->toBe(15.39);
    expect($p->model_file_ref)->toBe('models3d/mystic-dragon-3015782.3mf');
    expect($p->dimensions['unit'])->toBe('mm');
    expect((float) $p->dimensions['l'])->toBe(100.0);
    expect((float) $p->dimensions['w'])->toBe(100.0);
    expect((float) $p->dimensions['h'])->toBe(100.0);
    // A CSV must never self-publish or self-verify.
    expect($p->model_preview_verified)->toBeFalse();
    expect($p->estimates_verified)->toBeFalse();
});

it('converges an imported MODEL_3D row onto a linked Model3D row + non-blocking IP flag', function (): void {
    // Phase 6 converge: the CSV importer no longer writes a bare Product - a
    // queued enrichment job (sync in tests) links a Model3D provenance row and
    // runs the IP screen. Uses a blocklisted name ("pikachu") to assert the
    // NON-BLOCKING flag lands (tag, not CANNOT_PUBLISH).
    seedPricing();
    Http::fake(); // no real thumbnail fetch
    Sanctum::actingAs($this->superadmin);

    $csv = IMPORT_HEADER."\n"
        .'Pikachu Stand,MODEL_3D,characters,A stand,9.00,SGD,1,50,50,50,80,FDM,MAKE_TO_ORDER,'
        .'false,OWNED,creator,true,PENDING,https://cdn.example.com/p.png,https://makerworld.com/en/models/999123,'
        ."999123,models3d/pikachu-stand-999123.3mf,PLA,#FFCC00,80,300\n";

    $this->postJson('/api/admin/products/import', ['file' => importCsv($csv)])->assertOk();

    $p = Product::where('source_product_id', '999123')->firstOrFail();
    expect($p->model3d_id)->not->toBeNull();
    expect($p->ip_flagged)->toBeTrue();
    expect($p->ip_flag_reason)->toBe('blocklist:pikachu');
    // Non-blocking: still PENDING (the importer forces it), never CANNOT_PUBLISH.
    expect($p->publish_state->value)->toBe('PENDING');

    $model = Model3D::find($p->model3d_id);
    expect($model)->not->toBeNull();
    expect($model->source->value)->toBe('MAKERWORLD');
    expect($model->source_id)->toBe('999123');
});

it('forbids non-superadmin staff', function (): void {
    Sanctum::actingAs($this->staff);

    $csv = IMPORT_HEADER."\nX,MODEL_3D,,,,SGD,1,,,,,FDM,MAKE_TO_ORDER,,OWNED,,,,,,,,,,,\n";
    $this->postJson('/api/admin/products/import', ['file' => importCsv($csv)])->assertForbidden();
});

it('validates every row and skips invalid ones without blocking valid ones', function (): void {
    Sanctum::actingAs($this->superadmin);

    $csv = IMPORT_HEADER."\n"
        // valid
        .'Good One,MODEL_3D,,,5,SGD,1,10,10,10,50,FDM,MAKE_TO_ORDER,false,CC_BY,cred,true,PENDING,,,111,,,,,'."\n"
        // invalid: blank name + bad print_method + negative cost
        .',MODEL_3D,,,-3,SGD,1,,,,,LASER,MAKE_TO_ORDER,,OWNED,,,,,,222,,,,,'."\n"
        // invalid: unknown license
        .'Bad License,MODEL_3D,,,5,SGD,1,,,,,FDM,MAKE_TO_ORDER,,WTFPL,,,,,,333,,,,,'."\n";

    $res = $this->postJson('/api/admin/products/import', ['file' => importCsv($csv)])
        ->assertStatus(207); // multi-status: some rows failed

    $res->assertJsonPath('data.created', 1);
    $res->assertJsonPath('data.skipped', 2);

    expect(Product::where('source_product_id', '111')->exists())->toBeTrue();
    expect(Product::where('source_product_id', '222')->exists())->toBeFalse();
    expect(Product::where('source_product_id', '333')->exists())->toBeFalse();
});

it('is idempotent — re-importing the same source_product_id updates, not duplicates', function (): void {
    Sanctum::actingAs($this->superadmin);

    $row = fn (string $cost) => IMPORT_HEADER."\n"
        ."Repeat,MODEL_3D,,,{$cost},SGD,1,10,10,10,50,FDM,MAKE_TO_ORDER,false,OWNED,c,true,PENDING,,,999,,,,,\n";

    $this->postJson('/api/admin/products/import', ['file' => importCsv($row('5'))])->assertOk();
    $this->postJson('/api/admin/products/import', ['file' => importCsv($row('9.5'))])
        ->assertOk()
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.updated', 1);

    expect(Product::where('source_product_id', '999')->count())->toBe(1);
    expect((float) Product::where('source_product_id', '999')->first()->base_cost)->toBe(9.5);
});

it('rejects a model_file_ref with path traversal', function (): void {
    Sanctum::actingAs($this->superadmin);

    $csv = IMPORT_HEADER."\n"
        .'Evil,MODEL_3D,,,5,SGD,1,,,,,FDM,MAKE_TO_ORDER,,OWNED,,,,,,444,../../../etc/passwd,,,,'."\n";

    $this->postJson('/api/admin/products/import', ['file' => importCsv($csv)])
        ->assertStatus(207)
        ->assertJsonPath('data.skipped', 1);

    expect(Product::where('source_product_id', '444')->exists())->toBeFalse();
});

it('rejects a non-CSV upload', function (): void {
    Sanctum::actingAs($this->superadmin);

    $bad = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');
    $this->postJson('/api/admin/products/import', ['file' => $bad])->assertStatus(422);
});
