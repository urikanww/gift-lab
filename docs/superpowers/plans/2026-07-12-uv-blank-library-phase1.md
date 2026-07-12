# UV Blank Library — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff build a curated UV-blank library by capturing any product URL into the existing `SCRAPED_UV` gate, store multiple ranked buy links per blank, and surface those links on the per-order buy-list.

**Architecture:** Reuse the existing `SCRAPED_UV` ingestion (`ScrapedCatalogueService` + `CompletenessGate`) and buy-list (`AdminReorderController` + `ReorderBuyListPage`). Add three new pieces: a `source_links` JSON column on `products`, a `ListingCapture` service that fetches one URL and extracts public fields, and an admin capture endpoint + "Add blank by URL" control. No affiliate API, no scraper.

**Tech Stack:** Laravel 11 (PHP 8.3), Pest tests, Eloquent, `Illuminate\Support\Facades\Http`; React + TypeScript frontend, Vitest.

**Scope note:** This is Phase 1 of the [design](../specs/2026-07-12-uv-blank-library-design.md). Follow-up plans (not here): Discovery B (keyword puller), pre-filter (IP/material flags), corporate pricing (artwork fee + buy-at-PO).

**Commit trailer:** every commit message in this plan must end with a second `-m` paragraph:
`Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

---

## File structure

**Backend (create):**
- `database/migrations/2026_07_12_000001_add_source_links_to_products.php` — the JSON column.
- `app/Support/SourceLinks.php` — pure helper: normalize a link, add/merge into a list (dedupe by url), derive the primary url, guess `kind` from host.
- `app/Services/Catalogue/ListingCapture.php` — fetch one URL, extract name/price/image → `ScrapedProductData`.
- `app/Http/Controllers/AdminBlankCaptureController.php` — the capture endpoint.
- `tests/Unit/SourceLinksTest.php`, `tests/Unit/ProductSourceLinksTest.php`, `tests/Unit/ListingCaptureTest.php`, `tests/Feature/AdminBlankCaptureTest.php`, `tests/Feature/BuyListSourceLinksTest.php`.

**Backend (modify):**
- `app/Models/Product.php` — fillable + cast `source_links`; `worstCaseBlankCost()`; derive `source_url` from `source_links` in the saving hook.
- `app/Http/Controllers/AdminReorderController.php:87-109` — serialize `source_links`.
- `routes/api.php:166` — register the capture route.
- `database/factories/ProductFactory.php:40-49` — `scrapedUv()` seeds `source_links`.

**Frontend (create):**
- `frontend/src/lib/sourceLinks.ts` — pure `primarySourceLink()` selector + type.
- `frontend/src/lib/sourceLinks.test.ts` — Vitest.

**Frontend (modify):**
- `frontend/src/types.ts:376-388` — add `source_links` to `AdminReorder`; export `SourceLink`.
- `frontend/src/pages/ReorderBuyListPage.tsx:101-112` — render all links, primary highlighted, with a re-check caption.
- One admin page — add an "Add blank by URL" control (Task 7).

---

## Task 1: `source_links` column + Product model

**Files:**
- Create: `database/migrations/2026_07_12_000001_add_source_links_to_products.php`
- Modify: `app/Models/Product.php:37-75` (fillable), `:77-101` (casts), `:108-126` (saving hook)
- Modify: `database/factories/ProductFactory.php:40-49`
- Test: `tests/Unit/ProductSourceLinksTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Product;

it('derives source_url from the first local link on save', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_url' => null,
        'source_links' => [
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
            ['label' => 'LocalCo', 'url' => 'https://localco.sg/mug', 'kind' => 'local', 'price' => 12.0, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);

    expect($product->fresh()->source_url)->toBe('https://localco.sg/mug');
});

it('falls back to the first link when no local link exists', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_url' => null,
        'source_links' => [
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);

    expect($product->fresh()->source_url)->toBe('https://shopee.sg/product/1/2');
});

it('casts source_links to an array', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_links' => [['label' => 'X', 'url' => 'https://x.sg/1', 'kind' => 'local', 'price' => 1.0, 'currency' => 'SGD', 'last_checked' => null]],
    ]);

    expect($product->fresh()->source_links)->toBeArray()->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ProductSourceLinksTest.php`
Expected: FAIL — `source_links` unknown column / not an array cast.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_12_000001_add_source_links_to_products.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple ranked buy links per blank (UV blank library): local SG primary for
 * speed + marketplace plain-URL backups. `source_url` stays as the derived
 * primary so existing buy-list / "View source" consumers keep working.
 * Shape: [{label, url, kind: local|marketplace, price, currency, last_checked}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('source_links')->nullable()->after('source_url')
                ->comment('[{label,url,kind,price,currency,last_checked}] - buy links per blank');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('source_links');
        });
    }
};
```

- [ ] **Step 4: Wire the model**

In `app/Models/Product.php`, add `'source_links',` to `$fillable` (after `'source_url',` on line 55).

In `casts()` (after `'dimensions' => 'array',` line 84) add:

```php
            'source_links' => 'array',
```

Add a new saving hook inside `booted()`, right after the category-classifier hook closes (after line 135):

```php
        // Keep source_url as the derived primary buy link: first local link,
        // else the first link. Only when source_links is populated, so legacy
        // rows (MODEL_3D / manual) that set source_url directly are untouched.
        static::saving(function (Product $product): void {
            $links = $product->source_links;
            if (is_array($links) && $links !== []) {
                $product->source_url = \App\Support\SourceLinks::primaryUrl($links);
            }
        });
```

> `SourceLinks::primaryUrl()` is created in Task 3. This task's test seeds links whose primary is unambiguous; run Task 3 before Task 1's test passes if executing strictly in order — or implement `primaryUrl` inline now. To keep tasks independent, **do Task 3 first if your test run needs it**; the plan orders them 1→3 only for reading. (See Task 3.)

In `database/factories/ProductFactory.php`, extend `scrapedUv()` (line 42-48) to seed a link so existing behaviour has a link too:

```php
    public function scrapedUv(): static
    {
        return $this->state(fn (): array => [
            'class' => 'SCRAPED_UV',
            'stock_mode' => 'MAKE_TO_ORDER',
            'source_url' => $this->faker->url(),
            'source_product_id' => (string) $this->faker->randomNumber(8),
            'stock_estimate' => $this->faker->numberBetween(0, 500),
            'source_links' => [[
                'label' => 'Source',
                'url' => $this->faker->url(),
                'kind' => 'marketplace',
                'price' => $this->faker->randomFloat(2, 2, 40),
                'currency' => 'SGD',
                'last_checked' => null,
            ]],
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/ProductSourceLinksTest.php`
Expected: PASS (requires Task 3's `SourceLinks::primaryUrl`).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_12_000001_add_source_links_to_products.php app/Models/Product.php database/factories/ProductFactory.php tests/Unit/ProductSourceLinksTest.php
git commit -m "feat(catalogue): source_links column + derived primary source_url" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Worst-case blank cost accessor

**Files:**
- Modify: `app/Models/Product.php` (add method near other helpers)
- Test: `tests/Unit/ProductSourceLinksTest.php` (append)

- [ ] **Step 1: Write the failing test** (append to `tests/Unit/ProductSourceLinksTest.php`)

```php
it('returns the highest link price as worst-case blank cost', function (): void {
    $product = new Product([
        'class' => 'SCRAPED_UV',
        'source_links' => [
            ['label' => 'A', 'url' => 'https://a.sg/1', 'kind' => 'local', 'price' => 12.0, 'currency' => 'SGD', 'last_checked' => null],
            ['label' => 'B', 'url' => 'https://b.sg/1', 'kind' => 'marketplace', 'price' => 15.5, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);

    expect($product->worstCaseBlankCost())->toBe(15.5);
});

it('returns null worst-case cost when no links have prices', function (): void {
    $product = new Product(['class' => 'SCRAPED_UV', 'source_links' => []]);
    expect($product->worstCaseBlankCost())->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ProductSourceLinksTest.php`
Expected: FAIL — `Call to undefined method worstCaseBlankCost()`.

- [ ] **Step 3: Add the method** to `app/Models/Product.php` (after the `booted()` method, before the class closes):

```php
    /**
     * Worst-case blank cost = the highest price across source_links, used to
     * quote B2C off the priciest source so per-order retail drift can't turn a
     * job unprofitable. Null when no link carries a price.
     */
    public function worstCaseBlankCost(): ?float
    {
        $prices = [];
        foreach ((array) $this->source_links as $link) {
            if (isset($link['price']) && is_numeric($link['price'])) {
                $prices[] = (float) $link['price'];
            }
        }

        return $prices === [] ? null : max($prices);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/ProductSourceLinksTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Product.php tests/Unit/ProductSourceLinksTest.php
git commit -m "feat(catalogue): worst-case blank cost from source_links" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `SourceLinks` helper

**Files:**
- Create: `app/Support/SourceLinks.php`
- Test: `tests/Unit/SourceLinksTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\SourceLinks;

it('guesses marketplace kind from a shopee host', function (): void {
    expect(SourceLinks::guessKind('https://shopee.sg/product/1/2'))->toBe('marketplace');
    expect(SourceLinks::guessKind('https://www.lazada.sg/products/x.html'))->toBe('marketplace');
});

it('guesses local kind for any other host', function (): void {
    expect(SourceLinks::guessKind('https://blankco.sg/mug'))->toBe('local');
});

it('normalizes a link, filling defaults', function (): void {
    $link = SourceLinks::normalize(['url' => 'https://shopee.sg/product/1/2', 'price' => 9.9]);

    expect($link['url'])->toBe('https://shopee.sg/product/1/2')
        ->and($link['kind'])->toBe('marketplace')
        ->and($link['currency'])->toBe('SGD')
        ->and($link['label'])->toBe('shopee.sg')
        ->and($link)->toHaveKey('last_checked');
});

it('adds a link and dedupes by url (last write wins on price)', function (): void {
    $links = SourceLinks::add([], ['url' => 'https://a.sg/1', 'price' => 10.0]);
    $links = SourceLinks::add($links, ['url' => 'https://a.sg/1', 'price' => 11.0]);

    expect($links)->toHaveCount(1)
        ->and($links[0]['price'])->toBe(11.0);
});

it('returns the first local url as primary, else the first', function (): void {
    $links = [
        SourceLinks::normalize(['url' => 'https://shopee.sg/product/1/2']),
        SourceLinks::normalize(['url' => 'https://blankco.sg/mug']),
    ];
    expect(SourceLinks::primaryUrl($links))->toBe('https://blankco.sg/mug');
    expect(SourceLinks::primaryUrl([$links[0]]))->toBe('https://shopee.sg/product/1/2');
    expect(SourceLinks::primaryUrl([]))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/SourceLinksTest.php`
Expected: FAIL — class `App\Support\SourceLinks` not found.

- [ ] **Step 3: Implement the helper**

`app/Support/SourceLinks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helpers for a product's source_links list — the ranked buy links per UV
 * blank. Shape: [{label, url, kind: local|marketplace, price, currency,
 * last_checked}]. Marketplace hosts are known plain-URL storefronts; everything
 * else is treated as a local supplier.
 */
final class SourceLinks
{
    private const MARKETPLACE_HOSTS = ['shopee.', 'lazada.', 'amazon.', 'aliexpress.', 'taobao.', '1688.', 'qoo10.'];

    public static function guessKind(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::MARKETPLACE_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'marketplace';
            }
        }

        return 'local';
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array{label:string,url:string,kind:string,price:float|null,currency:string,last_checked:string|null}
     */
    public static function normalize(array $link): array
    {
        $url = (string) ($link['url'] ?? '');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            'label' => trim((string) ($link['label'] ?? '')) ?: ($host !== '' ? $host : 'Source'),
            'url' => $url,
            'kind' => in_array($link['kind'] ?? null, ['local', 'marketplace'], true)
                ? (string) $link['kind']
                : self::guessKind($url),
            'price' => isset($link['price']) && is_numeric($link['price']) ? (float) $link['price'] : null,
            'currency' => (string) ($link['currency'] ?? 'SGD'),
            'last_checked' => isset($link['last_checked']) ? (string) $link['last_checked'] : null,
        ];
    }

    /**
     * Add/merge a link into the list, deduped by url (last write wins).
     *
     * @param  array<int, array<string, mixed>>  $links
     * @param  array<string, mixed>  $link
     * @return array<int, array<string, mixed>>
     */
    public static function add(array $links, array $link): array
    {
        $normalized = self::normalize($link);
        $out = [];
        $replaced = false;
        foreach ($links as $existing) {
            if (($existing['url'] ?? null) === $normalized['url']) {
                $out[] = $normalized;
                $replaced = true;

                continue;
            }
            $out[] = self::normalize($existing);
        }
        if (! $replaced) {
            $out[] = $normalized;
        }

        return array_values($out);
    }

    /**
     * @param  array<int, array<string, mixed>>  $links
     */
    public static function primaryUrl(array $links): ?string
    {
        foreach ($links as $link) {
            if (($link['kind'] ?? null) === 'local' && ! empty($link['url'])) {
                return (string) $link['url'];
            }
        }

        return isset($links[0]['url']) && $links[0]['url'] !== '' ? (string) $links[0]['url'] : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/SourceLinksTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/SourceLinks.php tests/Unit/SourceLinksTest.php
git commit -m "feat(catalogue): SourceLinks helper (normalize/add/primary/kind)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: `ListingCapture` service

**Files:**
- Create: `app/Services/Catalogue/ListingCapture.php`
- Test: `tests/Unit/ListingCaptureTest.php`

Extracts name/price/image from one page: prefer JSON-LD `Product`, then Open Graph, then `<title>`. Works on standard HTML/OG pages (most local suppliers). Returns the existing `ScrapedProductData` DTO. Dimensions/weight stay null (staff completes in the gate).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\Catalogue\ListingCapture;
use Illuminate\Support\Facades\Http;

it('extracts name/price/image from JSON-LD Product', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <script type="application/ld+json">
        {"@type":"Product","name":"Ceramic Mug 440ml","image":"https://cdn.sg/mug.jpg",
         "offers":{"@type":"Offer","price":"12.90","priceCurrency":"SGD"}}
        </script>
        </head><body></body></html>
        HTML, 200)]);

    $data = app(ListingCapture::class)->capture('https://blankco.sg/mug-440');

    expect($data->name)->toBe('Ceramic Mug 440ml')
        ->and($data->price)->toBe(12.90)
        ->and($data->imageUrl)->toBe('https://cdn.sg/mug.jpg')
        ->and($data->sourceUrl)->toBe('https://blankco.sg/mug-440')
        ->and($data->printable)->toBeFalse();
});

it('falls back to Open Graph tags when no JSON-LD', function (): void {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html><head>
        <meta property="og:title" content="Blank Tumbler 600ml">
        <meta property="og:image" content="https://cdn.sg/tumbler.png">
        <meta property="product:price:amount" content="8.50">
        </head><body></body></html>
        HTML, 200)]);

    $data = app(ListingCapture::class)->capture('https://blankco.sg/tumbler');

    expect($data->name)->toBe('Blank Tumbler 600ml')
        ->and($data->price)->toBe(8.50)
        ->and($data->imageUrl)->toBe('https://cdn.sg/tumbler.png');
});

it('derives shopId_itemId as sourceProductId for a shopee url', function (): void {
    Http::fake(['*' => Http::response('<html><head><title>x</title></head></html>', 200)]);

    $data = app(ListingCapture::class)->capture('https://shopee.sg/product/1505484155/49207854779');

    expect($data->sourceProductId)->toBe('1505484155_49207854779');
});

it('returns null on a failed fetch', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect(app(ListingCapture::class)->capture('https://blankco.sg/down'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/ListingCaptureTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service**

`app/Services/Catalogue/ListingCapture.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Services\Scraper\ScrapedProductData;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Capture-on-browse: fetch ONE product page (staff-initiated) and extract public
 * fields into a ScrapedProductData draft. Prefers JSON-LD Product, then Open
 * Graph, then <title>. Standard HTML/OG pages (most local suppliers) extract
 * cleanly; JS-heavy anti-bot marketplaces may only yield the URL-derived id +
 * whatever OG the shell serves — staff completes the rest in the gate.
 */
final class ListingCapture
{
    public function capture(string $url): ?ScrapedProductData
    {
        try {
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                    .'(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->connectTimeout(5)->timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $html = $res->body();
        $ld = $this->fromJsonLd($html);
        $name = $ld['name'] ?? $this->ogContent($html, 'og:title') ?? $this->titleTag($html);
        $price = $ld['price'] ?? $this->priceMeta($html);
        $image = $ld['image'] ?? $this->ogContent($html, 'og:image');

        return new ScrapedProductData(
            sourceProductId: $this->deriveId($url),
            sourceUrl: $url,
            name: $name !== null ? trim($name) : null,
            price: $price,
            dimensions: null,
            weight: null,
            stockEstimate: null,
            imageUrl: $image,
            printable: false,
        );
    }

    /** @return array{name?:string,price?:float,image?:string} */
    private function fromJsonLd(string $html): array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $block) {
            $json = json_decode(trim($block), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($this->flattenLd($json) as $node) {
                $type = $node['@type'] ?? null;
                $isProduct = $type === 'Product' || (is_array($type) && in_array('Product', $type, true));
                if (! $isProduct) {
                    continue;
                }
                $offer = $node['offers'] ?? [];
                if (isset($offer[0])) {
                    $offer = $offer[0];
                }
                $img = $node['image'] ?? null;
                if (is_array($img)) {
                    $img = $img[0] ?? null;
                }

                return array_filter([
                    'name' => isset($node['name']) ? (string) $node['name'] : null,
                    'price' => isset($offer['price']) && is_numeric($offer['price']) ? (float) $offer['price'] : null,
                    'image' => $img !== null ? (string) $img : null,
                ], fn ($v) => $v !== null);
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function flattenLd(array $json): array
    {
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            return $json['@graph'];
        }
        if (array_is_list($json)) {
            return $json;
        }

        return [$json];
    }

    private function ogContent(string $html, string $property): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']'.preg_quote($property, '#').'["\'][^>]+content=["\'](.*?)["\']#i', $html, $m)) {
            return html_entity_decode($m[1]);
        }

        return null;
    }

    private function priceMeta(string $html): ?float
    {
        foreach (['product:price:amount', 'og:price:amount'] as $prop) {
            $v = $this->ogContent($html, $prop);
            if ($v !== null && is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }

    /**
     * Shopee/Lazada-style "/{shopId}/{itemId}" → "{shopId}_{itemId}" (matches the
     * affiliate client's id format so the same item dedupes). Otherwise a stable
     * host+path slug.
     */
    private function deriveId(string $url): string
    {
        if (preg_match('#/(\d{5,})/(\d{5,})#', $url, $m)) {
            return "{$m[1]}_{$m[2]}";
        }
        if (preg_match('#i\.(\d+)\.(\d+)#', $url, $m)) {
            return "{$m[1]}_{$m[2]}";
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim($host.$path, '/') ?: $url;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/ListingCaptureTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Catalogue/ListingCapture.php tests/Unit/ListingCaptureTest.php
git commit -m "feat(catalogue): ListingCapture extracts a listing from one URL" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Admin capture endpoint

**Files:**
- Create: `app/Http/Controllers/AdminBlankCaptureController.php`
- Modify: `routes/api.php:166` (add route inside the `auth:sanctum` group)
- Test: `tests/Feature/AdminBlankCaptureTest.php`

Flow: validate `url` → `ListingCapture::capture` → `ScrapedCatalogueService::ingest` (lands `CANNOT_PUBLISH` in the gate) → seed the first `source_links` entry from the captured data → return the product.

- [ ] **Step 1: Write the failing test**

```php
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

it('requires auth', function (): void {
    $this->postJson('/api/admin/blank-candidates/capture', ['url' => 'https://blankco.sg/x'])
        ->assertStatus(401);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminBlankCaptureTest.php`
Expected: FAIL — route `/api/admin/blank-candidates/capture` not found (404/405).

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/AdminBlankCaptureController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Catalogue\ListingCapture;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Support\SourceLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Capture-on-browse: staff paste any product URL (Shopee/Lazada/local supplier)
 * and it becomes a draft SCRAPED_UV blank in the completeness gate, seeded with
 * that URL as its first source link. Staff then complete dimensions/weight/print
 * details and add alternate buy links before publishing.
 */
final class AdminBlankCaptureController extends Controller
{
    public function store(
        Request $request,
        ListingCapture $capture,
        ScrapedCatalogueService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $data = $capture->capture($validated['url']);
        if ($data === null) {
            return response()->json(['message' => 'Could not read that page. Try again or add the blank manually.'], 502);
        }

        $product = $service->ingest($data);

        // Seed the captured URL as the first buy link (idempotent on re-capture).
        $product->source_links = SourceLinks::add((array) $product->source_links, [
            'url' => $data->sourceUrl,
            'price' => $data->price,
            'currency' => 'SGD',
            'last_checked' => Carbon::now()->toIso8601String(),
        ]);
        $product->save();

        return response()->json(['data' => [
            'id' => $product->id,
            'name' => $product->name,
            'publish_state' => $product->publish_state->value,
            'image_url' => $product->image_url,
            'source_url' => $product->source_url,
            'source_links' => $product->source_links,
        ]]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, inside the `auth:sanctum` group, after line 166 (the supplier-reorders routes) add:

```php
    // Capture-on-browse: paste a product URL -> draft SCRAPED_UV blank in the gate.
    Route::post('/admin/blank-candidates/capture', [\App\Http\Controllers\AdminBlankCaptureController::class, 'store']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/AdminBlankCaptureTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminBlankCaptureController.php routes/api.php tests/Feature/AdminBlankCaptureTest.php
git commit -m "feat(catalogue): admin capture-on-browse endpoint" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Buy-list surfaces multiple source links (backend)

**Files:**
- Modify: `app/Http/Controllers/AdminReorderController.php:92-109`
- Test: `tests/Feature/BuyListSourceLinksTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\SupplierReorder;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('returns source_links on each reorder for the buy-list', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_links' => [
            ['label' => 'LocalCo', 'url' => 'https://localco.sg/mug', 'kind' => 'local', 'price' => 12.0, 'currency' => 'SGD', 'last_checked' => null],
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);
    $variant = Variant::factory()->create(['product_id' => $product->id]);
    SupplierReorder::factory()->create(['variant_id' => $variant->id, 'state' => 'DRAFT']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/supplier-reorders')->assertOk();

    $row = collect($res->json('data'))->firstWhere('product_id', $product->id);
    expect($row['source_links'])->toHaveCount(2)
        ->and($row['source_links'][0]['url'])->toBe('https://localco.sg/mug')
        ->and($row['source_url'])->toBe('https://localco.sg/mug');
});
```

> If `Variant`/`SupplierReorder` factories differ, mirror the setup already used in the repo's reorder tests (search `tests/Feature` for `SupplierReorder::factory`). Keep the assertion on `source_links`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuyListSourceLinksTest.php`
Expected: FAIL — `source_links` key absent from the serialized reorder.

- [ ] **Step 3: Add `source_links` to the serializer**

In `app/Http/Controllers/AdminReorderController.php`, in `serialize()` (after line 107, the `source_url` entry):

```php
            // All ranked buy links for this blank (local primary + marketplace
            // backups). source_url above stays the derived primary for callers
            // that only want one. Prices are indicative - re-check before buying.
            'source_links' => $variant?->product?->source_links ?? [],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/BuyListSourceLinksTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AdminReorderController.php tests/Feature/BuyListSourceLinksTest.php
git commit -m "feat(reorder): expose source_links on the buy-list" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Frontend — link selector, buy-list render, "Add blank by URL"

**Files:**
- Create: `frontend/src/lib/sourceLinks.ts`, `frontend/src/lib/sourceLinks.test.ts`
- Modify: `frontend/src/types.ts:376-388`, `frontend/src/pages/ReorderBuyListPage.tsx:101-112`
- Modify: an admin page to add the capture control (see Step 6)

- [ ] **Step 1: Write the failing test**

`frontend/src/lib/sourceLinks.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { primarySourceLink, type SourceLink } from './sourceLinks';

const local: SourceLink = { label: 'LocalCo', url: 'https://localco.sg/mug', kind: 'local', price: 12, currency: 'SGD', last_checked: null };
const market: SourceLink = { label: 'Shopee', url: 'https://shopee.sg/product/1/2', kind: 'marketplace', price: 9.9, currency: 'SGD', last_checked: null };

describe('primarySourceLink', () => {
  it('prefers the first local link', () => {
    expect(primarySourceLink([market, local])?.url).toBe('https://localco.sg/mug');
  });
  it('falls back to the first link', () => {
    expect(primarySourceLink([market])?.url).toBe('https://shopee.sg/product/1/2');
  });
  it('returns null for empty', () => {
    expect(primarySourceLink([])).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/lib/sourceLinks.test.ts`
Expected: FAIL — module `./sourceLinks` not found.

- [ ] **Step 3: Implement the selector + type**

`frontend/src/lib/sourceLinks.ts`:

```ts
export interface SourceLink {
  label: string;
  url: string;
  kind: 'local' | 'marketplace';
  price: number | null;
  currency: string;
  last_checked: string | null;
}

/** First local link (fastest to fulfil), else the first link, else null. */
export function primarySourceLink(links: SourceLink[]): SourceLink | null {
  return links.find((l) => l.kind === 'local' && l.url) ?? links[0] ?? null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/lib/sourceLinks.test.ts`
Expected: PASS.

- [ ] **Step 5: Wire the type + buy-list render**

In `frontend/src/types.ts`, add to `AdminReorder` (after `source_url` line 386):

```ts
  source_links: import('./lib/sourceLinks').SourceLink[];
```

In `frontend/src/pages/ReorderBuyListPage.tsx`, replace the `Source` cell (lines 101-112) with a render of all links, primary highlighted, plus a re-check caption:

```tsx
                    <div className="col-span-2 sm:col-span-1">
                      <dt className="text-fg-subtle">Source</dt>
                      <dd className="flex flex-wrap gap-2">
                        {(r.source_links ?? []).length > 0 ? (
                          (r.source_links ?? []).map((l, i) => (
                            <a
                              key={l.url}
                              href={l.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className={i === 0 ? 'font-medium text-primary underline' : 'text-fg-muted underline'}
                            >
                              {l.label}
                              {l.price != null ? ` · ${l.currency} ${l.price}` : ''}
                            </a>
                          ))
                        ) : r.source_url ? (
                          <a href={r.source_url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                            Buy
                          </a>
                        ) : (
                          <span className="text-fg-muted">-</span>
                        )}
                      </dd>
                      {(r.source_links ?? []).length > 0 && (
                        <p className="mt-1 text-xs text-fg-subtle">Prices indicative — re-check stock & price on the listing before buying.</p>
                      )}
                    </div>
```

- [ ] **Step 6: Add the "Add blank by URL" control**

On the catalogue admin page (`frontend/src/pages/CatalogueAdminPage.tsx`), add a small form that POSTs to the capture endpoint and refreshes the list. Place it near the existing class filter (around line 402 where the `SCRAPED_UV` option lives). Minimal implementation:

```tsx
// near other useState hooks
const [captureUrl, setCaptureUrl] = useState('');
const [capturing, setCapturing] = useState(false);

const captureBlank = async () => {
  if (!captureUrl.trim() || capturing) return;
  setCapturing(true);
  try {
    await ensureCsrf();
    await api.post('/admin/blank-candidates/capture', { url: captureUrl.trim() });
    setCaptureUrl('');
    await load(); // the page's existing catalogue reload
    toast({ title: 'Blank captured', description: 'Complete its specs in the gate.', tone: 'success' });
  } catch (err) {
    toast({ title: 'Capture failed', description: apiError(err), tone: 'danger' });
  } finally {
    setCapturing(false);
  }
};
```

```tsx
// in the toolbar JSX
<div className="flex gap-2">
  <input
    type="url"
    value={captureUrl}
    onChange={(e) => setCaptureUrl(e.target.value)}
    placeholder="Paste a product URL to add a blank"
    className="rounded border border-border bg-bg px-2 py-1 text-sm"
  />
  <Button variant="outline" loading={capturing} onClick={() => void captureBlank()}>Add blank by URL</Button>
</div>
```

> Match the page's existing imports (`api`, `ensureCsrf`, `apiError`, `useToast`, `Button`) and its reload function name (it may be `load`, `fetchItems`, or a store action — use whatever that page already calls). Do not invent new data-fetching; reuse the page's pattern.

- [ ] **Step 7: Verify frontend builds + tests pass**

Run: `cd frontend && npx vitest run src/lib/sourceLinks.test.ts && npm run build`
Expected: test PASS, build succeeds (no TS errors).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/lib/sourceLinks.ts frontend/src/lib/sourceLinks.test.ts frontend/src/types.ts frontend/src/pages/ReorderBuyListPage.tsx frontend/src/pages/CatalogueAdminPage.tsx
git commit -m "feat(admin): add blank by URL + multi-source buy links in buy-list" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: Full-suite verification

- [ ] **Step 1: Run the backend migration on the test DB + full PHP suite**

Run: `php artisan test --filter='SourceLinks|ListingCapture|AdminBlankCapture|BuyListSourceLinks|ProductSourceLinks'`
Expected: all PASS.

- [ ] **Step 2: Run the whole backend suite to catch regressions**

Run: `php artisan test`
Expected: green (no regressions in existing catalogue/reorder tests).

- [ ] **Step 3: Run the frontend suite + build**

Run: `cd frontend && npx vitest run && npm run build`
Expected: green.

- [ ] **Step 4: Manual smoke via preview (optional but recommended)**

Start the app, open the catalogue admin, paste a local-supplier product URL, confirm a draft `SCRAPED_UV` blank appears `CANNOT_PUBLISH`, add a second link, and confirm the buy-list shows both links with the primary highlighted.

---

## Self-review

**Spec coverage:**
- Curated library in `SCRAPED_UV` gate — Tasks 4-5 (capture → `ScrapedCatalogueService::ingest`, lands in gate). ✓
- Multiple source links (local primary + marketplace backup) — Tasks 1, 3, 6, 7. ✓
- Discovery A (capture-on-browse) — Tasks 4-5, 7 (Step 6). ✓
- Buy-per-order buy-list surfacing links — Tasks 6-7. ✓
- Worst-case blank cost (pricing foundation) — Task 2. ✓
- Deferred by design (separate plans): Discovery B, pre-filter, corporate pricing/artwork fee, min-order (none — accepted). Noted in header. ✓

**Placeholder scan:** every code step contains full code; commands have expected output. Task 6 Step 1 and Task 7 Step 6 include a caveat to match existing factory/page patterns rather than inventing them — these are guidance, not placeholders (the code shown is complete). ✓

**Type consistency:** `SourceLinks::primaryUrl` / `guessKind` / `normalize` / `add` used consistently across Tasks 1, 3, 5; `worstCaseBlankCost()` Task 2; `ScrapedProductData` constructor matches the existing DTO (Task 4); frontend `SourceLink` shape mirrors the backend link shape and `AdminReorder.source_links` (Tasks 6-7). ✓
