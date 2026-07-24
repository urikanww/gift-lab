<?php

declare(strict_types=1);

use App\Enums\JobTrack;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QueueService;

it('gives a batched UV job one labelled file per approved UV line', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROCURING', 'accepted_at' => now()]);
    // SCRAPED_UV maps to the UV track (ProductClass::track()).
    $p1 = Product::factory()->create(['name' => 'Cap', 'class' => 'SCRAPED_UV']);
    $p2 = Product::factory()->create(['name' => 'Mug', 'class' => 'SCRAPED_UV']);
    $l1 = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $p1->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/cap.png'], 'line_state' => 'READY']);
    $l2 = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $p2->id, 'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/mug.png'], 'line_state' => 'READY']);
    Proof::factory()->forLine($l1)->create(['state' => 'APPROVED', 'artwork_version_ref' => 'artwork/cap.png']);
    Proof::factory()->forLine($l2)->create(['state' => 'APPROVED', 'artwork_version_ref' => 'artwork/mug.png']);

    $jobs = app(QueueService::class)->buildJobsForQuote($quote->fresh());

    $uv = $jobs->firstWhere('track', JobTrack::Uv);
    expect($uv->artwork_refs)->toHaveCount(2);
    expect(collect($uv->artwork_refs)->pluck('ref')->all())->toContain('artwork/cap.png')->toContain('artwork/mug.png');
    expect(collect($uv->artwork_refs)->pluck('product_name')->all())->toContain('Cap')->toContain('Mug');
});
