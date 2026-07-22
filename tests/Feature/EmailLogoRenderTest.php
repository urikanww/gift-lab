<?php

declare(strict_types=1);

use App\Mail\ProofChangesRequestedMail;
use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * The flask logo is CID-embedded, which only materialises when the mailable is
 * actually sent (render() has no $message). So send through the array transport
 * (sync queue) and inspect the built Symfony message.
 */
function sentHtml(Mailable $mailable): string
{
    // Mailable::send() renders + sends synchronously (ignores ShouldQueue), so
    // the CID embed materialises and lands in the array transport immediately.
    $mailable->to('buyer@example.test')->send(app(Illuminate\Contracts\Mail\Factory::class));
    // ArrayTransport::messages() is a Collection.
    $email = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return (string) $email->getHtmlBody();
}

it('embeds the flask logo (cid) + coral wordmark in the quote-ready email', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $product = Product::factory()->create(['base_cost' => 1]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id]);

    $html = sentHtml(new QuoteReadyMail($quote, false, null));

    expect($html)->toContain('color:#ff3b5f;">Lab<')  // coral "Lab" wordmark
        ->and($html)->toContain('cid:')               // flask mark embedded inline
        ->and($html)->not->toContain('#6b4de6');      // no leftover purple
});

it('embeds the flask logo (cid) in the shell-based proof-changes email', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'state' => 'SENT', 'version' => 1]);

    $html = sentHtml(new ProofChangesRequestedMail($quote, $proof));

    expect($html)->toContain('color:#ff3b5f;">Lab<')
        ->and($html)->toContain('cid:');
});
