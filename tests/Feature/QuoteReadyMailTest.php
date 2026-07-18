<?php

declare(strict_types=1);

use App\Mail\QuoteReadyMail;
use App\Models\Quote;
use App\Models\User;

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

it('escapes a malicious creator name in the greeting', function (): void {
    $creator = User::factory()->create(['name' => 'Mallory<script>alert(1)</script>']);
    $quote = Quote::factory()->create(['created_by' => $creator->id]);
    $mail = new QuoteReadyMail($quote, hasProof: false, proofImageUrl: null);

    // second arg false = literal needle (no auto-escaping): the raw tag must be absent.
    $mail->assertDontSeeInHtml('<script>', false);
});
