<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ProductClass;
use App\Enums\PrintMethod;
use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Services\Scraper\Contracts\ScraperClient;
use App\Services\Scraper\ScrapedProductData;

/**
 * Scraped-UV catalogue lifecycle (spec 6.4): ingest to our own DB, gate on
 * completeness, honour the auto-publish toggle, and on daily re-sync detect
 * price drift / dead sources and auto-pull from public. A background re-sync
 * never mutates a quote's frozen snapshot — snapshots live on line_items and
 * are untouched here.
 */
final class ScrapedCatalogueService
{
    public function __construct(
        private readonly ScraperClient $scraper,
        private readonly CompletenessGate $gate,
    ) {
    }

    /**
     * Ingest (create or update) a scraped listing and set its publish state.
     */
    public function ingest(ScrapedProductData $data): Product
    {
        $product = Product::withTrashed()
            ->where('class', ProductClass::ScrapedUv->value)
            ->where('source_product_id', $data->sourceProductId)
            ->first()
            ?? new Product(['class' => ProductClass::ScrapedUv->value]);

        $this->applyData($product, $data);
        $this->evaluateAndSetState($product);

        return $product;
    }

    /**
     * Daily re-sync + drift detection for one product.
     */
    public function resync(Product $product): Product
    {
        $data = $product->source_product_id !== null
            ? $this->scraper->fetch($product->source_product_id)
            : null;

        if ($data === null || $data->sourceDead) {
            return $this->markCannotPublish($product, ['source_dead']);
        }

        $oldPrice = (float) $product->base_cost;
        $threshold = (float) PricingConfig::value('catalogue', 'drift_pct', 10);

        if ($oldPrice > 0 && $data->price !== null) {
            $driftPct = abs($data->price - $oldPrice) / $oldPrice * 100;
            if ($driftPct > $threshold) {
                // Reflect the drifted price, then pull from public for re-review.
                $this->applyData($product, $data);

                return $this->markCannotPublish($product, ['needs_re-review']);
            }
        }

        $this->applyData($product, $data);
        $this->evaluateAndSetState($product);

        return $product;
    }

    /**
     * Admin approves a completed, gated item for public listing.
     */
    public function publish(Product $product): Product
    {
        if ($this->gate->isComplete($product)) {
            $product->publish_state = PublishState::Published;
            $product->cannot_publish_reasons = null;
            $product->save();
        }

        return $product;
    }

    public function unpublish(Product $product): Product
    {
        $product->publish_state = PublishState::ReadyToApprove;
        $product->save();

        return $product;
    }

    private function applyData(Product $product, ScrapedProductData $data): void
    {
        $product->class = ProductClass::ScrapedUv;
        $product->source_product_id = $data->sourceProductId;
        $product->source_url = $data->sourceUrl;
        $product->name = $data->name ?? $product->name ?? 'Untitled scraped item';
        $product->base_cost = $data->price ?? 0;
        $product->dimensions = $data->dimensions;
        $product->weight = $data->weight;
        $product->stock_estimate = $data->stockEstimate;
        $product->image_url = $data->imageUrl;
        $product->is_printable = $data->printable;
        $product->print_method = $data->printable ? PrintMethod::Uv : null;
        $product->stock_mode = 'MAKE_TO_ORDER';
        $product->save();
    }

    private function evaluateAndSetState(Product $product): void
    {
        $reasons = $this->gate->reasons($product);

        if ($reasons !== []) {
            $this->markCannotPublish($product, $reasons);

            return;
        }

        $autoPublish = (bool) PricingConfig::value('catalogue', 'auto_publish', false);
        $product->publish_state = $autoPublish ? PublishState::Published : PublishState::ReadyToApprove;
        $product->cannot_publish_reasons = null;
        $product->save();
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function markCannotPublish(Product $product, array $reasons): Product
    {
        $product->publish_state = PublishState::CannotPublish;
        $product->cannot_publish_reasons = $reasons;
        $product->save();

        return $product;
    }
}
