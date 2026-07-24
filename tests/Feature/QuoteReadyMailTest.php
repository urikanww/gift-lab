<?php

declare(strict_types=1);

use App\Enums\OrderMilestone;
use App\Mail\OrderMilestoneMail;
use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/** A customized line on the quote that takes a proof. */
function mailProofLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
}

it('builds the quote+proof variant with a subject', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: true, proofImageUrl: 'https://x/img');

    $mail->assertHasSubject('Your quote & proof are ready to review — Gift Lab');
    // assertSeeInHtml() HTML-escapes its argument (double_encode=true) before
    // searching, so passing an already-escaped needle like 'Review &amp;
    // approve' would look for the double-escaped 'Review &amp;amp; approve'
    // in the rendered output - which only exists if the CTA text is itself
    // broken (visibly shows "&amp;" in the email). Pass the plain text; the
    // assertion still proves the "&" is correctly HTML-escaped in the CTA.
    $mail->assertSeeInHtml('Review & approve');
});

it('uses the quote-only subject when no proof', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    $mail->assertHasSubject('Your quote is ready to review — Gift Lab');
});

it('links the CTA to the reference-based order route, never /quotes/{id}', function (): void {
    config(['app.frontend_url' => 'https://app.example.test']);
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    // The SPA routes an order detail at /orders/{reference} only; /quotes/{id}
    // falls through to the catch-all and renders NotFound, so the buyer's one
    // CTA would be a dead link.
    $mail->assertSeeInHtml('https://app.example.test/orders/'.$quote->reference, false);
    $mail->assertDontSeeInHtml('/quotes/'.$quote->id, false);
});

it('shows the searchable reference under Quote ref, not the tracking code', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    // The buyer types what this email calls "Quote ref" into the order search,
    // which matches on reference (and id) only. Handing them the tracking code
    // there gave them the one identifier the search cannot find.
    $mail->assertSeeInHtml('Quote ref', false);
    $mail->assertSeeInHtml($quote->reference, false);
});

it('still carries the tracking code, labelled for login-free tracking', function (): void {
    $quote = Quote::factory()->create();
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    // Not dropped, just disambiguated: the tracking code is the shareable one a
    // recipient without an account uses at /track.
    expect($quote->tracking_code)->not->toBeNull();
    $mail->assertSeeInHtml('Tracking code', false);
    $mail->assertSeeInHtml($quote->tracking_code, false);
    $mail->assertSeeInHtml('Share to track without an account', false);
});

it('escapes a malicious creator name in the greeting', function (): void {
    $creator = User::factory()->create(['name' => 'Mallory<script>alert(1)</script>']);
    $quote = Quote::factory()->create(['created_by' => $creator->id]);
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    // second arg false = literal needle (no auto-escaping): the raw tag must be absent.
    $mail->assertDontSeeInHtml('<script>', false);
});

it('emails the buyer with the proof variant when a proof round is sent', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    $line = mailProofLine($quote);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", ['artwork_version_ref' => 'a/v1.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === true && $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

it('emails the buyer with the quote-only variant on plain send', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === false);
    Mail::assertQueuedCount(1);
});

it('emails the buyer with the proof variant when the first round is sent on an accepted quote', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'ACCEPTED', 'accepted_at' => now(), 'accepted_by' => $buyer->id, 'created_by' => $buyer->id]);
    $line = mailProofLine($quote);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", ['artwork_version_ref' => 'a/v1.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === true && $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

it('does not email a staff-created quote when the company has no buyer contact', function (): void {
    Mail::fake();
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['state' => 'DRAFT', 'created_by' => $staff->id]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertNothingQueued();
});

it('emails the company buyer contact for a staff-created quote', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'DRAFT', 'created_by' => $staff->id]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

// TODO(per-line): revision-round email wiring is Task 5.1. In the retired
// order-level flow a v2 proof deliberately sent the lighter ProofIssued
// milestone (not the heavy QuoteReadyMail), since the quote itself had not
// changed. The per-line flow has no separate "revision" path yet: every
// sendProofs round currently reuses the batched QuoteReadyMail (see
// QuoteService::emailProofsReady, an explicit stopgap) and sends no ProofIssued
// milestone. Which of those a revision round SHOULD send is unsettled and owned
// by Task 5.1, so this case is skipped rather than asserting either behavior.
it('sends the revision notice, not the quote email, on a v2 proof round', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'PROOFING', 'accepted_at' => now(), 'accepted_by' => $buyer->id, 'created_by' => $buyer->id]);
    $line = mailProofLine($quote);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", ['artwork_version_ref' => 'a/v2.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    Mail::assertNotQueued(QuoteReadyMail::class);
    Mail::assertQueued(
        OrderMilestoneMail::class,
        fn ($mail): bool => $mail->milestone === OrderMilestone::ProofIssued,
    );
})->skip('TODO(per-line): revision-round email design is Task 5.1; per-line send currently reuses the batched QuoteReadyMail and sends no ProofIssued milestone.');
