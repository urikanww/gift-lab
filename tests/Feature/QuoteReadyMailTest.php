<?php

declare(strict_types=1);

use App\Mail\QuoteReadyMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

it('emails the buyer with the proof variant on slim send', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png'])->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === true && $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

it('emails the buyer with the quote-only variant on plain send', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === false);
    Mail::assertQueuedCount(1);
});

it('emails the buyer with the proof variant when the first proof is issued', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'ACCEPTED', 'accepted_at' => now(), 'accepted_by' => $buyer->id, 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/proofs", ['artwork_version_ref' => 'a/v1.png'])->assertCreated();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasProof === true && $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

it('does not email a staff-created quote when the company has no buyer contact', function (): void {
    Mail::fake();
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['state' => 'DRAFT', 'created_by' => $staff->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertNothingQueued();
});

it('emails the company buyer contact for a staff-created quote', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'DRAFT', 'created_by' => $staff->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    Mail::assertQueued(QuoteReadyMail::class, fn ($m) => $m->hasTo($buyer->email));
    Mail::assertQueuedCount(1);
});

// Was: 'does not re-email on a v2 proof (already in proofing)'. That silence
// was the defect, not the design — the buyer waited on a proof already sitting
// in front of them and staff phoned every time. A revision still sends no
// QuoteReadyMail (that one carries the quote itself, and the quote has not
// changed); it sends the lighter milestone email instead.
it('sends the revision notice, not the quote email, on a v2 proof', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'PROOFING', 'accepted_at' => now(), 'accepted_by' => $buyer->id, 'created_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/proofs", ['artwork_version_ref' => 'a/v2.png'])->assertCreated();

    Mail::assertNotQueued(App\Mail\QuoteReadyMail::class);
    Mail::assertQueued(
        App\Mail\OrderMilestoneMail::class,
        fn ($mail): bool => $mail->milestone === App\Enums\OrderMilestone::ProofIssued,
    );
});
