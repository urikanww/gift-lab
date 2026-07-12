# Blank Recommender + Gift-Ideas Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An in-app staff recommender (keyword → ranked Shopee Affiliate candidates → "Add as blank" into the gate) plus a curated public gift-ideas page (affiliate links + "Personalize with us" cross-sell) that keeps the affiliate account compliant.

**Architecture:** Extend `HttpShopeeAffiliateClient` with a richer `searchCandidates()`; a staff-gated recommender API + page; a `gift_idea_features` table populated by staff "Feature publicly"; a cached public `/gift-ideas` endpoint + page; a daily refresh command. Adding a candidate reuses `ScrapedCatalogueService::ingest` (lands in the gate). Public links use `offerLink` (affiliate); procurement keeps the plain `productLink`.

**Tech Stack:** Laravel 11 (PHP 8.3), Pest, `Illuminate\Support\Facades\Http` + `Cache`; React + TypeScript, Vitest, react-router.

**Design:** [blank-recommender-gift-ideas-design.md](../specs/2026-07-12-blank-recommender-gift-ideas-design.md)

**⚠️ Owner action item (not code), gating public go-live:** verify what Shopee's affiliate program actually requires (live gallery vs periodic activity). Build proceeds; keep the public page unlinked from nav until confirmed.

**Commit trailer:** every commit ends with a second `-m` paragraph:
`Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

---

## File structure

**Backend (create):**
- `app/Services/Scraper/ShopeeCandidate.php` — rich candidate DTO.
- `app/Services/Catalogue/CandidateScreen.php` — IP + material advisory flags.
- `app/Http/Controllers/AdminBlankRecommendationController.php` — search / add / feature / unfeature.
- `app/Http/Controllers/GiftIdeasController.php` — public list.
- `app/Models/GiftIdeaFeature.php`, `database/factories/GiftIdeaFeatureFactory.php`.
- `database/migrations/2026_07_12_000003_create_gift_idea_features_table.php`.
- `app/Console/Commands/RefreshGiftIdeas.php`.
- Tests: `tests/Unit/CandidateScreenTest.php`, `tests/Feature/ShopeeCandidateSearchTest.php`, `tests/Feature/BlankRecommendationTest.php`, `tests/Feature/GiftIdeasTest.php`, `tests/Feature/RefreshGiftIdeasTest.php`.

**Backend (modify):**
- `app/Services/Scraper/HttpShopeeAffiliateClient.php` — add `searchCandidates()`.
- `routes/api.php` — recommender routes (staff) + `/gift-ideas` (public).
- `routes/console.php` — schedule `giftideas:refresh`.

**Frontend (create):**
- `frontend/src/pages/BlankRecommendationPage.tsx` + `.test.tsx`.
- `frontend/src/pages/GiftIdeasPage.tsx` + `.test.tsx`.
- `frontend/src/lib/recommendations.ts` (types + api calls).

**Frontend (modify):**
- `frontend/src/App.tsx` — routes (`/blank-recommendations` staffOnly, `/gift-ideas` public).
- `frontend/src/pages/CatalogueAdminPage.tsx` — nav link to the recommender.

---

## Task 1: `ShopeeCandidate` DTO + `searchCandidates()`

**Files:**
- Create: `app/Services/Scraper/ShopeeCandidate.php`
- Modify: `app/Services/Scraper/HttpShopeeAffiliateClient.php`
- Test: `tests/Feature/ShopeeCandidateSearchTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/ShopeeCandidateSearchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Scraper\HttpShopeeAffiliateClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // The recommender uses the live affiliate client directly; give it creds +
    // a fake endpoint (phpunit blanks these so the fixture is used elsewhere).
    config([
        'services.shopee_affiliate.app_id' => 'test-app',
        'services.shopee_affiliate.secret' => 'test-secret',
        'services.shopee_affiliate.base_url' => 'https://aff.test/graphql',
    ]);
});

it('maps affiliate nodes into ShopeeCandidate objects', function (): void {
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        [
            'itemId' => 26094497054, 'shopId' => 1505484155,
            'productName' => 'Embossed Tulip Ceramic Mug 440ml', 'priceMin' => '25.90',
            'imageUrl' => 'https://cf.shopee.sg/mug.jpg',
            'productLink' => 'https://shopee.sg/product/1505484155/26094497054',
            'offerLink' => 'https://s.shopee.sg/abc123',
            'sales' => 320, 'ratingStar' => '4.8', 'shopName' => 'CeramicCo',
        ],
    ]]]], 200)]);

    $out = app(HttpShopeeAffiliateClient::class)->searchCandidates('ceramic mug', 5);

    expect($out)->toHaveCount(1);
    $c = $out[0];
    expect($c->sourceProductId)->toBe('1505484155_26094497054')
        ->and($c->name)->toBe('Embossed Tulip Ceramic Mug 440ml')
        ->and($c->price)->toBe(25.90)
        ->and($c->productLink)->toBe('https://shopee.sg/product/1505484155/26094497054')
        ->and($c->offerLink)->toBe('https://s.shopee.sg/abc123')
        ->and($c->sales)->toBe(320)
        ->and($c->ratingStar)->toBe(4.8)
        ->and($c->shopName)->toBe('CeramicCo');
});

it('returns empty when credentials are missing', function (): void {
    config(['services.shopee_affiliate.app_id' => '', 'services.shopee_affiliate.secret' => '']);
    expect(app(HttpShopeeAffiliateClient::class)->searchCandidates('mug', 5))->toBe([]);
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Feature/ShopeeCandidateSearchTest.php`
Expected: FAIL — `searchCandidates` undefined.

- [ ] **Step 3: Create the DTO**

`app/Services/Scraper/ShopeeCandidate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * A ranked recommender candidate from the Shopee Affiliate feed. Carries BOTH
 * links deliberately: productLink (plain, for buy-per-order procurement) and
 * offerLink (affiliate tracking, for the public gift-ideas page ONLY). Never
 * use offerLink for our own checkout (self-referral).
 */
final readonly class ShopeeCandidate
{
    public function __construct(
        public string $sourceProductId,
        public string $name,
        public ?float $price,
        public string $currency,
        public ?string $imageUrl,
        public string $productLink,
        public string $offerLink,
        public int $sales,
        public ?float $ratingStar,
        public ?string $shopName,
    ) {}
}
```

- [ ] **Step 4: Add `searchCandidates()` to `HttpShopeeAffiliateClient`**

Add this method (after the existing `search()` method):

```php
    /**
     * Richer keyword search for the staff recommender: includes sales, rating,
     * shop and the affiliate offerLink for ranking + public featuring.
     *
     * @return array<int, ShopeeCandidate>
     */
    public function searchCandidates(string $keyword, int $limit = 20): array
    {
        $query = <<<'GQL'
        query ($keyword: String!, $limit: Int!) {
          productOfferV2(keyword: $keyword, limit: $limit) {
            nodes {
              itemId
              shopId
              productName
              priceMin
              imageUrl
              productLink
              offerLink
              sales
              ratingStar
              shopName
            }
          }
        }
        GQL;

        $result = $this->request($query, ['keyword' => $keyword, 'limit' => $limit]);
        $nodes = $result['productOfferV2']['nodes'] ?? [];

        return collect($nodes)
            ->filter(fn ($n): bool => is_array($n) && ! empty($n['itemId']) && ! empty($n['shopId']))
            ->map(fn (array $n): ShopeeCandidate => new ShopeeCandidate(
                sourceProductId: "{$n['shopId']}_{$n['itemId']}",
                name: (string) ($n['productName'] ?? ''),
                price: isset($n['priceMin']) && is_numeric($n['priceMin']) ? (float) $n['priceMin'] : null,
                currency: 'SGD',
                imageUrl: isset($n['imageUrl']) ? (string) $n['imageUrl'] : null,
                productLink: (string) ($n['productLink'] ?? ''),
                offerLink: (string) ($n['offerLink'] ?? ''),
                sales: (int) ($n['sales'] ?? 0),
                ratingStar: isset($n['ratingStar']) && is_numeric($n['ratingStar']) ? (float) $n['ratingStar'] : null,
                shopName: isset($n['shopName']) ? (string) $n['shopName'] : null,
            ))
            ->values()
            ->all();
    }
```

Add the import at the top if not already resolvable (same namespace, so no import needed — `ShopeeCandidate` is in `App\Services\Scraper`).

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test tests/Feature/ShopeeCandidateSearchTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Scraper/ShopeeCandidate.php app/Services/Scraper/HttpShopeeAffiliateClient.php tests/Feature/ShopeeCandidateSearchTest.php
git commit -m "feat(recommender): ShopeeCandidate DTO + searchCandidates" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `CandidateScreen` (IP + material flags)

**Files:**
- Create: `app/Services/Catalogue/CandidateScreen.php`
- Test: `tests/Unit/CandidateScreenTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/CandidateScreenTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Catalogue\CandidateScreen;

it('flags known IP/branded names', function (): void {
    $s = new CandidateScreen();
    expect($s->ipFlag('Disney Frozen Ceramic Mug'))->toBe('disney');
    expect($s->ipFlag('Sanrio Hello Kitty Tumbler'))->toBe('sanrio');
    expect($s->ipFlag('Plain Ceramic Mug 440ml'))->toBeNull();
});

it('flags likely non-UV materials', function (): void {
    $s = new CandidateScreen();
    expect($s->materialFlag('Cotton Canvas Tote Bag'))->toBe('fabric');
    expect($s->materialFlag('Plush Teddy Bear'))->toBe('plush');
    expect($s->materialFlag('Ceramic Coffee Mug'))->toBeNull();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Unit/CandidateScreenTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/Catalogue/CandidateScreen.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * Advisory screening for recommender candidates. Flags are shown to staff (never
 * auto-hidden in the recommender) but IP-flagged items are excluded from the
 * public gift-ideas page. Keyword lists are deliberately small + high-precision.
 */
final class CandidateScreen
{
    /** @var array<int, string> */
    private const BRANDS = [
        'disney', 'sanrio', 'hello kitty', 'pokemon', 'pokémon', 'marvel', 'dc comics',
        'nintendo', 'studio ghibli', 'bt21', 'bts', 'harry potter', 'star wars',
        'mofusand', 'kuromi', 'my melody', 'chiikawa', 'labubu',
    ];

    /** @var array<string, array<int, string>> flag => keywords */
    private const MATERIALS = [
        'fabric' => ['cotton', 'canvas', 'tote bag', 'linen', 'polyester', 'nylon', 't-shirt', 'apron'],
        'plush' => ['plush', 'teddy', 'stuffed'],
    ];

    public function ipFlag(string $name): ?string
    {
        $n = strtolower($name);
        foreach (self::BRANDS as $brand) {
            if (str_contains($n, $brand)) {
                // Normalise a couple of aliases to a single label.
                return match ($brand) {
                    'hello kitty', 'kuromi', 'my melody' => 'sanrio',
                    'pokémon' => 'pokemon',
                    default => $brand,
                };
            }
        }

        return null;
    }

    public function materialFlag(string $name): ?string
    {
        $n = strtolower($name);
        foreach (self::MATERIALS as $flag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($n, $kw)) {
                    return $flag;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test tests/Unit/CandidateScreenTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Catalogue/CandidateScreen.php tests/Unit/CandidateScreenTest.php
git commit -m "feat(recommender): CandidateScreen IP + material advisory flags" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `gift_idea_features` table + model + factory

**Files:**
- Create: `database/migrations/2026_07_12_000003_create_gift_idea_features_table.php`, `app/Models/GiftIdeaFeature.php`, `database/factories/GiftIdeaFeatureFactory.php`
- Test: `tests/Unit/GiftIdeaFeatureTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/GiftIdeaFeatureTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a featured gift idea and casts flags', function (): void {
    $f = GiftIdeaFeature::factory()->create(['ip_flagged' => true, 'price' => 12.50]);
    expect($f->fresh()->ip_flagged)->toBeTrue()
        ->and((float) $f->fresh()->price)->toBe(12.50);
});

it('is unique on source_product_id via updateOrCreate', function (): void {
    GiftIdeaFeature::updateOrCreate(['source_product_id' => 'S_1'], ['name' => 'A', 'offer_link' => 'https://s/1', 'product_link' => 'https://p/1']);
    GiftIdeaFeature::updateOrCreate(['source_product_id' => 'S_1'], ['name' => 'B', 'offer_link' => 'https://s/1', 'product_link' => 'https://p/1']);
    expect(GiftIdeaFeature::where('source_product_id', 'S_1')->count())->toBe(1)
        ->and(GiftIdeaFeature::where('source_product_id', 'S_1')->first()->name)->toBe('B');
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Unit/GiftIdeaFeatureTest.php`
Expected: FAIL — table/model missing.

- [ ] **Step 3: Migration**

`database/migrations/2026_07_12_000003_create_gift_idea_features_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-curated affiliate products featured on the public /gift-ideas page.
 * offer_link is the affiliate (commission) link shown publicly; product_link is
 * the plain listing (never shown to the public, kept for reference). ip_flagged
 * rows are stored but excluded from the public endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_idea_features', function (Blueprint $table): void {
            $table->id();
            $table->string('source_product_id')->unique();
            $table->string('name');
            $table->string('image_url')->nullable();
            $table->string('offer_link');
            $table->string('product_link');
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency', 3)->default('SGD');
            $table->string('shop_name')->nullable();
            $table->boolean('ip_flagged')->default(false);
            $table->integer('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['ip_flagged', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_idea_features');
    }
};
```

- [ ] **Step 4: Model + factory**

`app/Models/GiftIdeaFeature.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GiftIdeaFeature extends Model
{
    /** @use HasFactory<\Database\Factories\GiftIdeaFeatureFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'source_product_id', 'name', 'image_url', 'offer_link', 'product_link',
        'price', 'currency', 'shop_name', 'ip_flagged', 'sort', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'ip_flagged' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
```

`database/factories/GiftIdeaFeatureFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GiftIdeaFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GiftIdeaFeature>
 */
class GiftIdeaFeatureFactory extends Factory
{
    protected $model = GiftIdeaFeature::class;

    public function definition(): array
    {
        $shop = $this->faker->randomNumber(8);
        $item = $this->faker->randomNumber(8);

        return [
            'source_product_id' => "{$shop}_{$item}",
            'name' => ucwords($this->faker->words(3, true)),
            'image_url' => $this->faker->imageUrl(),
            'offer_link' => 'https://s.shopee.sg/'.$this->faker->lexify('??????'),
            'product_link' => "https://shopee.sg/product/{$shop}/{$item}",
            'price' => $this->faker->randomFloat(2, 3, 40),
            'currency' => 'SGD',
            'shop_name' => $this->faker->company(),
            'ip_flagged' => false,
            'sort' => 0,
            'created_by' => null,
        ];
    }
}
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test tests/Unit/GiftIdeaFeatureTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_12_000003_create_gift_idea_features_table.php app/Models/GiftIdeaFeature.php database/factories/GiftIdeaFeatureFactory.php tests/Unit/GiftIdeaFeatureTest.php
git commit -m "feat(gift-ideas): gift_idea_features table + model" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Recommender endpoints (search / add / feature / unfeature)

**Files:**
- Create: `app/Http/Controllers/AdminBlankRecommendationController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/BlankRecommendationTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/BlankRecommendationTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    config([
        'services.shopee_affiliate.app_id' => 'test-app',
        'services.shopee_affiliate.secret' => 'test-secret',
        'services.shopee_affiliate.base_url' => 'https://aff.test/graphql',
    ]);
});

function fakeCandidates(): void
{
    Http::fake(['aff.test/*' => Http::response(['data' => ['productOfferV2' => ['nodes' => [
        ['itemId' => 2, 'shopId' => 1, 'productName' => 'Disney Ceramic Mug', 'priceMin' => '20.00', 'imageUrl' => 'https://i/1', 'productLink' => 'https://shopee.sg/product/1/2', 'offerLink' => 'https://s.shopee.sg/aa', 'sales' => 10, 'ratingStar' => '4.5', 'shopName' => 'S1'],
        ['itemId' => 4, 'shopId' => 3, 'productName' => 'Plain Ceramic Mug 440ml', 'priceMin' => '9.90', 'imageUrl' => 'https://i/2', 'productLink' => 'https://shopee.sg/product/3/4', 'offerLink' => 'https://s.shopee.sg/bb', 'sales' => 300, 'ratingStar' => '4.9', 'shopName' => 'S2'],
    ]]]], 200)]);
}

it('returns ranked candidates with IP/material flags (staff only)', function (): void {
    fakeCandidates();
    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/blank-recommendations?keyword=mug&limit=10')->assertOk();

    $data = $res->json('data');
    // Ranked by sales desc: the 300-sales plain mug first.
    expect($data[0]['source_product_id'])->toBe('3_4')
        ->and($data[0]['ip_flag'])->toBeNull()
        ->and(collect($data)->firstWhere('source_product_id', '1_2')['ip_flag'])->toBe('disney');
});

it('forbids non-staff on search', function (): void {
    $buyer = User::factory()->create(['role' => 'buyer']);
    Sanctum::actingAs($buyer);
    $this->getJson('/api/admin/blank-recommendations?keyword=mug')->assertStatus(403);
});

it('adds a candidate as a SCRAPED_UV blank in the gate with the plain product link', function (): void {
    Sanctum::actingAs($this->staff);
    $res = $this->postJson('/api/admin/blank-recommendations/add', [
        'source_product_id' => '3_4', 'name' => 'Plain Ceramic Mug 440ml', 'price' => 9.90,
        'image_url' => 'https://i/2', 'product_link' => 'https://shopee.sg/product/3/4',
    ])->assertOk();

    $product = Product::findOrFail($res->json('data.id'));
    expect($product->class->value)->toBe('SCRAPED_UV')
        ->and($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->source_links[0]['url'])->toBe('https://shopee.sg/product/3/4');
});

it('features + unfeatures a candidate for the public page', function (): void {
    Sanctum::actingAs($this->staff);
    $this->postJson('/api/admin/blank-recommendations/feature', [
        'source_product_id' => '3_4', 'name' => 'Plain Ceramic Mug 440ml', 'price' => 9.90,
        'image_url' => 'https://i/2', 'offer_link' => 'https://s.shopee.sg/bb',
        'product_link' => 'https://shopee.sg/product/3/4', 'shop_name' => 'S2', 'ip_flagged' => false,
    ])->assertOk();

    $f = GiftIdeaFeature::where('source_product_id', '3_4')->firstOrFail();
    expect($f->offer_link)->toBe('https://s.shopee.sg/bb');

    $this->deleteJson("/api/admin/blank-recommendations/feature/{$f->id}")->assertOk();
    expect(GiftIdeaFeature::where('source_product_id', '3_4')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Feature/BlankRecommendationTest.php`
Expected: FAIL — routes missing.

- [ ] **Step 3: Controller**

`app/Http/Controllers/AdminBlankRecommendationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftIdeaFeature;
use App\Services\Catalogue\CandidateScreen;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Scraper\HttpShopeeAffiliateClient;
use App\Services\Scraper\ScrapedProductData;
use App\Support\SourceLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Staff blank recommender: keyword -> ranked Shopee affiliate candidates ->
 * "Add as blank" (into the gate) or "Feature publicly" (gift-ideas page).
 * Read-only against the affiliate API; adding reuses the scraped-UV ingest.
 */
final class AdminBlankRecommendationController extends Controller
{
    public function index(Request $request, HttpShopeeAffiliateClient $client, CandidateScreen $screen): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $keyword = trim((string) $request->string('keyword'));
        if ($keyword === '') {
            return response()->json(['data' => []]);
        }
        $limit = max(1, min((int) $request->integer('limit', 20), 50));

        $candidates = collect($client->searchCandidates($keyword, $limit))
            ->sortByDesc('sales')
            ->map(fn ($c): array => [
                'source_product_id' => $c->sourceProductId,
                'name' => $c->name,
                'price' => $c->price,
                'currency' => $c->currency,
                'image_url' => $c->imageUrl,
                'product_link' => $c->productLink,
                'offer_link' => $c->offerLink,
                'sales' => $c->sales,
                'rating_star' => $c->ratingStar,
                'shop_name' => $c->shopName,
                'ip_flag' => $screen->ipFlag($c->name),
                'material_flag' => $screen->materialFlag($c->name),
            ])
            ->values();

        return response()->json(['data' => $candidates]);
    }

    public function add(Request $request, ScrapedCatalogueService $service): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $v = $request->validate([
            'source_product_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:512'],
            'price' => ['nullable', 'numeric'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'product_link' => ['required', 'url', 'max:2048'],
        ]);

        $product = $service->ingest(new ScrapedProductData(
            sourceProductId: $v['source_product_id'],
            sourceUrl: $v['product_link'],
            name: $v['name'],
            price: isset($v['price']) ? (float) $v['price'] : null,
            dimensions: null, weight: null, stockEstimate: null,
            imageUrl: $v['image_url'] ?? null,
            printable: false,
        ));

        // Seed the PLAIN product link for buy-per-order procurement (not offerLink).
        $product->source_links = SourceLinks::add((array) $product->source_links, [
            'url' => $v['product_link'],
            'price' => $v['price'] ?? null,
            'currency' => 'SGD',
            'last_checked' => Carbon::now()->toIso8601String(),
        ]);
        $product->save();

        return response()->json(['data' => ['id' => $product->id, 'publish_state' => $product->publish_state->value]]);
    }

    public function feature(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $v = $request->validate([
            'source_product_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:512'],
            'price' => ['nullable', 'numeric'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'offer_link' => ['required', 'url', 'max:2048'],
            'product_link' => ['required', 'url', 'max:2048'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'ip_flagged' => ['nullable', 'boolean'],
        ]);

        $feature = GiftIdeaFeature::updateOrCreate(
            ['source_product_id' => $v['source_product_id']],
            [
                'name' => $v['name'], 'price' => $v['price'] ?? null,
                'image_url' => $v['image_url'] ?? null, 'offer_link' => $v['offer_link'],
                'product_link' => $v['product_link'], 'shop_name' => $v['shop_name'] ?? null,
                'ip_flagged' => (bool) ($v['ip_flagged'] ?? false),
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json(['data' => ['id' => $feature->id]]);
    }

    public function unfeature(Request $request, GiftIdeaFeature $feature): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);
        $feature->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
```

- [ ] **Step 4: Routes**

In `routes/api.php`, inside the `auth:sanctum` group (near the other `/admin/*` routes), add:

```php
    // Staff blank recommender (affiliate-powered discovery -> gate / gift-ideas).
    Route::get('/admin/blank-recommendations', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'index']);
    Route::post('/admin/blank-recommendations/add', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'add']);
    Route::post('/admin/blank-recommendations/feature', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'feature']);
    Route::delete('/admin/blank-recommendations/feature/{feature}', [\App\Http\Controllers\AdminBlankRecommendationController::class, 'unfeature']);
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test tests/Feature/BlankRecommendationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminBlankRecommendationController.php routes/api.php tests/Feature/BlankRecommendationTest.php
git commit -m "feat(recommender): staff search/add/feature endpoints" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Public `/gift-ideas` endpoint (cached, IP-safe)

**Files:**
- Create: `app/Http/Controllers/GiftIdeasController.php`
- Modify: `routes/api.php` (public group)
- Test: `tests/Feature/GiftIdeasTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/GiftIdeasTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('returns non-IP-flagged features publicly with offer links', function (): void {
    GiftIdeaFeature::factory()->create(['name' => 'Plain Mug', 'ip_flagged' => false, 'sort' => 1, 'offer_link' => 'https://s.shopee.sg/ok']);
    GiftIdeaFeature::factory()->create(['name' => 'Disney Mug', 'ip_flagged' => true, 'sort' => 2]);

    $res = $this->getJson('/api/gift-ideas')->assertOk();
    $names = collect($res->json('data'))->pluck('name');

    expect($names)->toContain('Plain Mug')->not->toContain('Disney Mug')
        ->and($res->json('data.0.offer_link'))->toBe('https://s.shopee.sg/ok');
});

it('does not leak internal fields', function (): void {
    GiftIdeaFeature::factory()->create(['ip_flagged' => false]);
    $row = $this->getJson('/api/gift-ideas')->assertOk()->json('data.0');
    expect($row)->not->toHaveKeys(['product_link', 'created_by', 'id', 'source_product_id']);
});

it('needs no auth', function (): void {
    $this->getJson('/api/gift-ideas')->assertOk();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Feature/GiftIdeasTest.php`
Expected: FAIL — route missing.

- [ ] **Step 3: Controller**

`app/Http/Controllers/GiftIdeasController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftIdeaFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Public gift-ideas page feed: staff-curated affiliate products, IP-flagged rows
 * excluded. Only the affiliate offer_link + display fields are exposed - never
 * the plain product_link or internal ids. Cached; RefreshGiftIdeas busts it.
 */
final class GiftIdeasController extends Controller
{
    public const CACHE_KEY = 'gift_ideas.public';

    public function index(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            return GiftIdeaFeature::query()
                ->where('ip_flagged', false)
                ->orderBy('sort')
                ->orderByDesc('id')
                ->get()
                ->map(fn (GiftIdeaFeature $f): array => [
                    'name' => $f->name,
                    'image_url' => $f->image_url,
                    'offer_link' => $f->offer_link,
                    'price' => $f->price,
                    'currency' => $f->currency,
                    'shop_name' => $f->shop_name,
                ])
                ->all();
        });

        return response()->json(['data' => $data]);
    }
}
```

- [ ] **Step 4: Route** — in `routes/api.php`, inside the public `throttle:60,1` group (with the other `/catalogue` public routes):

```php
    Route::get('/gift-ideas', [\App\Http\Controllers\GiftIdeasController::class, 'index']);
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test tests/Feature/GiftIdeasTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GiftIdeasController.php routes/api.php tests/Feature/GiftIdeasTest.php
git commit -m "feat(gift-ideas): cached public /gift-ideas endpoint (IP-safe)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: `giftideas:refresh` command + schedule

**Files:**
- Create: `app/Console/Commands/RefreshGiftIdeas.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/RefreshGiftIdeasTest.php`

Re-fetches each featured item via the bound `ScraperClient` (affiliate in prod, fixture in tests), updates price, prunes dead sources, and busts the public cache.

- [ ] **Step 1: Write the failing test**

`tests/Feature/RefreshGiftIdeasTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use App\Services\Scraper\FixtureScraperClient;
use App\Services\Scraper\ScrapedProductData;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->client = app(FixtureScraperClient::class);
});

function featureListing(string $id, ?float $price, bool $dead = false): ScrapedProductData
{
    return new ScrapedProductData(
        sourceProductId: $id, sourceUrl: "https://shopee.sg/p/{$id}", name: 'Mug',
        price: $price, dimensions: null, weight: null, stockEstimate: null,
        imageUrl: null, printable: false, sourceDead: $dead,
    );
}

it('updates price on refresh', function (): void {
    $f = GiftIdeaFeature::factory()->create(['source_product_id' => 'S_1', 'price' => 5.00]);
    $this->client->with(featureListing('S_1', 8.00));

    $this->artisan('giftideas:refresh')->assertSuccessful();

    expect((float) $f->fresh()->price)->toBe(8.00);
});

it('prunes a dead featured source', function (): void {
    $f = GiftIdeaFeature::factory()->create(['source_product_id' => 'S_2']);
    $this->client->with(featureListing('S_2', null, dead: true));

    $this->artisan('giftideas:refresh')->assertSuccessful();

    expect(GiftIdeaFeature::where('source_product_id', 'S_2')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test tests/Feature/RefreshGiftIdeasTest.php`
Expected: FAIL — command missing.

- [ ] **Step 3: Command**

`app/Console/Commands/RefreshGiftIdeas.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\GiftIdeasController;
use App\Models\GiftIdeaFeature;
use App\Services\Scraper\Contracts\ScraperClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily refresh of the public gift-ideas features: re-fetch each item, update its
 * indicative price, and prune (soft-delete) any whose source went dead - so the
 * public page never shows stale prices or broken affiliate links.
 */
class RefreshGiftIdeas extends Command
{
    protected $signature = 'giftideas:refresh';

    protected $description = 'Refresh featured gift-idea prices and prune dead affiliate links.';

    public function handle(ScraperClient $scraper): int
    {
        $updated = 0;
        $pruned = 0;

        GiftIdeaFeature::query()->chunkById(100, function ($features) use ($scraper, &$updated, &$pruned): void {
            foreach ($features as $feature) {
                $data = $scraper->fetch($feature->source_product_id);
                if ($data === null || $data->sourceDead) {
                    $feature->delete();
                    $pruned++;

                    continue;
                }
                if ($data->price !== null) {
                    $feature->price = $data->price;
                    $feature->save();
                    $updated++;
                }
            }
        });

        Cache::forget(GiftIdeasController::CACHE_KEY);
        $this->info("Refreshed {$updated} feature(s), pruned {$pruned}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule** — in `routes/console.php`, after the existing schedules:

```php
// Daily refresh of public gift-ideas features: update prices + prune dead
// affiliate links so the public page never shows stale data / broken links.
Schedule::command('giftideas:refresh')
    ->dailyAt('05:30')
    ->onOneServer()
    ->withoutOverlapping();
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test tests/Feature/RefreshGiftIdeasTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/RefreshGiftIdeas.php routes/console.php tests/Feature/RefreshGiftIdeasTest.php
git commit -m "feat(gift-ideas): daily refresh + dead-link prune command" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Frontend — recommender page

**Files:**
- Create: `frontend/src/lib/recommendations.ts`, `frontend/src/pages/BlankRecommendationPage.tsx`, `frontend/src/pages/BlankRecommendationPage.test.tsx`
- Modify: `frontend/src/App.tsx`, `frontend/src/pages/CatalogueAdminPage.tsx`

- [ ] **Step 1: Types + api helper `frontend/src/lib/recommendations.ts`**

```ts
import api, { ensureCsrf } from './api';

export interface Candidate {
  source_product_id: string;
  name: string;
  price: number | null;
  currency: string;
  image_url: string | null;
  product_link: string;
  offer_link: string;
  sales: number;
  rating_star: number | null;
  shop_name: string | null;
  ip_flag: string | null;
  material_flag: string | null;
}

export async function searchCandidates(keyword: string, limit = 20): Promise<Candidate[]> {
  const { data } = await api.get<{ data: Candidate[] }>('/admin/blank-recommendations', {
    params: { keyword, limit },
  });
  return data.data;
}

export async function addBlank(c: Candidate): Promise<void> {
  await ensureCsrf();
  await api.post('/admin/blank-recommendations/add', {
    source_product_id: c.source_product_id,
    name: c.name,
    price: c.price,
    image_url: c.image_url,
    product_link: c.product_link,
  });
}

export async function featureCandidate(c: Candidate): Promise<void> {
  await ensureCsrf();
  await api.post('/admin/blank-recommendations/feature', {
    source_product_id: c.source_product_id,
    name: c.name,
    price: c.price,
    image_url: c.image_url,
    offer_link: c.offer_link,
    product_link: c.product_link,
    shop_name: c.shop_name,
    ip_flagged: c.ip_flag != null,
  });
}
```

- [ ] **Step 2: Write the failing page test `frontend/src/pages/BlankRecommendationPage.test.tsx`**

```tsx
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import BlankRecommendationPage from './BlankRecommendationPage';
import * as recs from '../lib/recommendations';

beforeEach(() => vi.restoreAllMocks());

const candidate: recs.Candidate = {
  source_product_id: '3_4', name: 'Plain Ceramic Mug 440ml', price: 9.9, currency: 'SGD',
  image_url: null, product_link: 'https://shopee.sg/product/3/4', offer_link: 'https://s.shopee.sg/bb',
  sales: 300, rating_star: 4.9, shop_name: 'S2', ip_flag: null, material_flag: null,
};

it('searches and renders ranked candidates', async () => {
  vi.spyOn(recs, 'searchCandidates').mockResolvedValue([candidate]);
  render(<ThemeProvider><MemoryRouter><BlankRecommendationPage /></MemoryRouter></ThemeProvider>);

  await userEvent.type(screen.getByLabelText(/keyword/i), 'mug');
  await userEvent.click(screen.getByRole('button', { name: /search/i }));

  await waitFor(() => expect(screen.getByText('Plain Ceramic Mug 440ml')).toBeInTheDocument());
  expect(screen.getByText(/300 sold/i)).toBeInTheDocument();
});
```

- [ ] **Step 3: Run, confirm FAIL**

Run: `cd frontend && npx vitest run src/pages/BlankRecommendationPage.test.tsx`
Expected: FAIL — module missing.

- [ ] **Step 4: Page `frontend/src/pages/BlankRecommendationPage.tsx`**

```tsx
import { useState } from 'react';
import { Badge, Button, Card, Input, useToast } from '../ui';
import { apiError } from '../lib/api';
import { addBlank, featureCandidate, searchCandidates, type Candidate } from '../lib/recommendations';

export default function BlankRecommendationPage() {
  const { toast } = useToast();
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [busy, setBusy] = useState<string | null>(null);

  const run = async () => {
    if (!keyword.trim() || loading) return;
    setLoading(true);
    try {
      setCandidates(await searchCandidates(keyword.trim()));
    } catch (err) {
      toast({ title: 'Search failed', description: apiError(err), tone: 'danger' });
    } finally {
      setLoading(false);
    }
  };

  const act = async (c: Candidate, kind: 'add' | 'feature') => {
    setBusy(`${kind}:${c.source_product_id}`);
    try {
      if (kind === 'add') await addBlank(c);
      else await featureCandidate(c);
      toast({ title: kind === 'add' ? 'Added to gate' : 'Featured', description: c.name, tone: 'success' });
    } catch (err) {
      toast({ title: 'Action failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-3xl text-fg">Blank recommendations</h1>
        <p className="mt-1 text-sm text-fg-muted">
          Search Shopee for UV-printable blanks. Add promising ones to the gate, or feature them on the public gift-ideas page.
        </p>
      </div>

      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Input label="Keyword" value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="ceramic mug, acrylic keychain…" />
        </div>
        <Button loading={loading} onClick={() => void run()}>Search</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {candidates.map((c) => (
          <Card key={c.source_product_id} padding="md" className="flex flex-col gap-2">
            {c.image_url && <img src={c.image_url} alt="" className="aspect-square w-full rounded object-cover" referrerPolicy="no-referrer" />}
            <p className="line-clamp-2 text-sm font-medium text-fg">{c.name}</p>
            <div className="flex flex-wrap gap-1.5 text-xs text-fg-subtle">
              <span className="font-semibold text-fg">{c.currency} {c.price ?? '—'}</span>
              <span>· {c.sales} sold</span>
              {c.rating_star != null && <span>· ★ {c.rating_star}</span>}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {c.ip_flag && <Badge tone="danger" size="sm">IP: {c.ip_flag}</Badge>}
              {c.material_flag && <Badge tone="warning" size="sm">{c.material_flag}</Badge>}
            </div>
            <div className="mt-auto flex gap-2 pt-2">
              <Button size="sm" loading={busy === `add:${c.source_product_id}`} onClick={() => void act(c, 'add')}>Add as blank</Button>
              <Button size="sm" variant="outline" loading={busy === `feature:${c.source_product_id}`} onClick={() => void act(c, 'feature')}>Feature</Button>
            </div>
          </Card>
        ))}
      </div>
    </section>
  );
}
```

- [ ] **Step 5: Register route + nav link**

In `frontend/src/App.tsx`, add alongside the other `staffOnly` admin routes (e.g. after the `catalogue-admin` route):

```tsx
              <Route path="blank-recommendations" element={<ProtectedRoute staffOnly><BlankRecommendationPage /></ProtectedRoute>} />
```

Add the import at the top with the other page imports:
```tsx
import BlankRecommendationPage from './pages/BlankRecommendationPage';
```

In `frontend/src/pages/CatalogueAdminPage.tsx`, add a link near the header (next to the auto-publish toggle) so staff can reach it — a simple react-router `Link`:
```tsx
<Link to="/blank-recommendations" className="text-sm text-primary underline">Find blanks ↗</Link>
```
(Import `Link` from `react-router-dom` if not already imported.)

- [ ] **Step 6: Run test + build**

Run: `cd frontend && npx vitest run src/pages/BlankRecommendationPage.test.tsx && npm run build`
Expected: PASS + clean build.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/lib/recommendations.ts frontend/src/pages/BlankRecommendationPage.tsx frontend/src/pages/BlankRecommendationPage.test.tsx frontend/src/App.tsx frontend/src/pages/CatalogueAdminPage.tsx
git commit -m "feat(recommender): staff blank-recommendations page" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: Frontend — public gift-ideas page

**Files:**
- Create: `frontend/src/pages/GiftIdeasPage.tsx`, `frontend/src/pages/GiftIdeasPage.test.tsx`
- Modify: `frontend/src/App.tsx`

- [ ] **Step 1: Write the failing test `frontend/src/pages/GiftIdeasPage.test.tsx`**

```tsx
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import GiftIdeasPage from './GiftIdeasPage';
import api from '../lib/api';

beforeEach(() => vi.restoreAllMocks());

it('renders featured products with affiliate links, disclosure and cross-sell', async () => {
  vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [
    { name: 'Plain Mug', image_url: null, offer_link: 'https://s.shopee.sg/ok', price: 9.9, currency: 'SGD', shop_name: 'S2' },
  ] } } as any);

  render(<ThemeProvider><MemoryRouter><GiftIdeasPage /></MemoryRouter></ThemeProvider>);

  await waitFor(() => expect(screen.getByText('Plain Mug')).toBeInTheDocument());
  // Affiliate disclosure present.
  expect(screen.getByText(/affiliate links/i)).toBeInTheDocument();
  // Buy link is the affiliate offer link, rel-hardened.
  const buy = screen.getByRole('link', { name: /buy on shopee/i });
  expect(buy).toHaveAttribute('href', 'https://s.shopee.sg/ok');
  expect(buy).toHaveAttribute('rel', expect.stringContaining('sponsored'));
  // Cross-sell CTA to our catalogue.
  expect(screen.getByRole('link', { name: /personalize with us/i })).toHaveAttribute('href', '/products');
});
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `cd frontend && npx vitest run src/pages/GiftIdeasPage.test.tsx`
Expected: FAIL — module missing.

- [ ] **Step 3: Page `frontend/src/pages/GiftIdeasPage.tsx`**

```tsx
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { Card } from '../ui';
import { AsyncBoundary } from '../components/ui/States';

interface GiftIdea {
  name: string;
  image_url: string | null;
  offer_link: string;
  price: number | null;
  currency: string;
  shop_name: string | null;
}

export default function GiftIdeasPage() {
  const [ideas, setIdeas] = useState<GiftIdea[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const { data } = await api.get<{ data: GiftIdea[] }>('/gift-ideas');
        setIdeas(data.data);
      } catch (err) {
        setError(apiError(err));
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  return (
    <section className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-3xl text-fg">Gift ideas</h1>
        <p className="mt-1 text-sm text-fg-muted">UV-printable gift inspiration. Love one? We can personalize it for you.</p>
        {/* Required affiliate disclosure. */}
        <p className="mt-2 text-xs text-fg-subtle">
          This page contains affiliate links — we may earn a commission if you buy through them, at no extra cost to you.
        </p>
      </div>

      <AsyncBoundary loading={loading} error={error} isEmpty={(ideas ?? []).length === 0} emptyTitle="No gift ideas yet.">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {(ideas ?? []).map((g, i) => (
            <Card key={`${g.offer_link}-${i}`} padding="md" className="flex flex-col gap-2">
              {g.image_url && <img src={g.image_url} alt="" className="aspect-square w-full rounded object-cover" referrerPolicy="no-referrer" />}
              <p className="line-clamp-2 text-sm font-medium text-fg">{g.name}</p>
              <p className="text-sm"><span className="font-semibold text-fg">{g.currency} {g.price ?? '—'}</span>{g.shop_name ? <span className="text-xs text-fg-subtle"> · {g.shop_name}</span> : null}</p>
              <div className="mt-auto flex flex-col gap-1.5 pt-2">
                <a href={g.offer_link} target="_blank" rel="sponsored nofollow noopener noreferrer" className="rounded-md bg-surface-2 px-3 py-1.5 text-center text-xs font-medium text-fg hover:bg-surface-3">
                  Buy on Shopee ↗
                </a>
                <Link to="/products" className="rounded-md bg-primary px-3 py-1.5 text-center text-xs font-semibold text-primary-fg">
                  Personalize with us →
                </Link>
              </div>
            </Card>
          ))}
        </div>
      </AsyncBoundary>
    </section>
  );
}
```

- [ ] **Step 4: Register public route** — in `frontend/src/App.tsx`, inside the public `Layout` block (with `products`, `kits` etc.):

```tsx
            <Route path="gift-ideas" element={<GiftIdeasPage />} />
```
Import at top:
```tsx
import GiftIdeasPage from './pages/GiftIdeasPage';
```

> Do NOT add `/gift-ideas` to the primary nav yet — it stays reachable-by-URL only until the owner confirms the affiliate program requirement (design concern #1).

- [ ] **Step 5: Run test + build**

Run: `cd frontend && npx vitest run src/pages/GiftIdeasPage.test.tsx && npm run build`
Expected: PASS + clean build.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/GiftIdeasPage.tsx frontend/src/pages/GiftIdeasPage.test.tsx frontend/src/App.tsx
git commit -m "feat(gift-ideas): public gift-ideas page (disclosure + cross-sell)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: Full verification

- [ ] **Step 1: Backend — new suites**

Run: `php artisan test --filter='ShopeeCandidate|CandidateScreen|GiftIdeaFeature|BlankRecommendation|GiftIdeas|RefreshGiftIdeas'`
Expected: all PASS.

- [ ] **Step 2: Whole backend suite (regressions)**

Run: `php artisan test`
Expected: green (437 baseline + the new tests; no regressions).

- [ ] **Step 3: Frontend targeted + build**

Run: `cd frontend && npx vitest run src/pages/BlankRecommendationPage.test.tsx src/pages/GiftIdeasPage.test.tsx && npm run build`
Expected: green (run these two files explicitly; the full vitest suite hangs at teardown on this machine).

- [ ] **Step 4: Manual smoke (optional)**

Staff page: `/blank-recommendations` (needs live affiliate creds) → search → Add / Feature. Public: `/gift-ideas` → featured rows, affiliate + cross-sell links, disclosure visible.

---

## Self-review

**Spec coverage:**
- Staff recommender (search/rank/add) — Tasks 1, 2, 4, 7. ✓
- IP/material pre-filter — Task 2 (+ surfaced in 4/7). ✓
- Add → gate with plain productLink — Task 4 `add`. ✓
- Curated gift-ideas (feature) — Task 4 `feature` + Task 3 table. ✓
- Public page: offerLink, disclosure, cross-sell, IP-excluded, cached, no-leak — Tasks 5, 8. ✓
- Refresh + dead-link prune — Task 6. ✓
- Link hygiene (offerLink public / productLink procurement) — Task 4 (add seeds productLink; feature stores offerLink) + Task 8 (`rel="sponsored nofollow"`). ✓
- Concern #1 (verify program) — public route unlinked from nav (Task 8 note) + plan header. ✓

**Placeholder scan:** all code steps complete; commands have expected output. Frontend route/nav edits reference existing patterns (ProtectedRoute staffOnly, Layout) with exact snippets. ✓

**Type consistency:** `ShopeeCandidate` (Task 1) → recommender payload (Task 4) → frontend `Candidate` (Task 7). `GiftIdeaFeature` fields (Task 3) → feature endpoint (Task 4) → public payload (Task 5) → frontend `GiftIdea` (Task 8). `GiftIdeasController::CACHE_KEY` shared by Task 5 + Task 6. ✓
```
