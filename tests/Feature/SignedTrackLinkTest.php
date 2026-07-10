<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Services\OrderTracker;
use Illuminate\Support\Str;

function frontendLinkFor(Quote $quote): string
{
    return app(OrderTracker::class)->signedFrontendLink($quote);
}

it('serves the tracking payload for a validly signed link', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    // The frontend link is /track/view?code=..&signature=..; the same query is
    // forwarded to the API route, which is what the signature was minted for.
    $query = Str::after(frontendLinkFor($quote), '?');

    $this->getJson("/api/track/view?{$query}")
        ->assertOk()
        ->assertJson(['reference' => $quote->tracking_code, 'stage' => 'REVIEW']);
});

it('rejects a tampered signature', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $query = Str::after(frontendLinkFor($quote), '?');

    $this->getJson("/api/track/view?{$query}0")->assertForbidden();
});

it('returns a generic 404 for a signed link to an unknown code', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $query = Str::after(frontendLinkFor($quote), '?');
    $quote->forceDelete();

    $this->getJson("/api/track/view?{$query}")->assertNotFound();
});

it('includes a signed tracking_link on the quote resource', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);

    $link = (new \App\Http\Resources\QuoteResource($quote))
        ->toArray(request());

    expect($link['tracking_link'])->toStartWith('/track/view?code=')
        ->and($link['tracking_link'])->toContain('signature=');
});
