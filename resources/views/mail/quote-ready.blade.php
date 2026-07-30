<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $hasProof ? 'Your quote & proof are ready to review' : 'Your quote is ready to review' }}</title>
<!--[if mso]>
<noscript>
<xml>
<o:OfficeDocumentSettings>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
</noscript>
<![endif]-->
<style>
    @media only screen and (max-width: 620px) {
        .gl-wrapper { width: 100% !important; }
        .gl-card { width: 100% !important; border-radius: 0 !important; }
        .gl-px { padding-left: 20px !important; padding-right: 20px !important; }
        .gl-stack { display: block !important; width: 100% !important; }
        .gl-btn-td { display: block !important; width: 100% !important; }
        .gl-btn { display: block !important; width: 100% !important; text-align: center !important; }
        .gl-headline { font-size: 22px !important; line-height: 28px !important; }
        .gl-proof-img { max-width: 100% !important; height: auto !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background-color:#f6f6fb; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f6f6fb;">
    {{ $hasProof ? 'Your quote and proof are ready to review and approve.' : 'Your quote is ready to review and approve.' }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f6fb;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" class="gl-wrapper" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                <tr>
                    <td align="center" style="padding-bottom:24px;">
                        {{-- Flask mark CID-embedded (survives image-blocking that
                             strips SVG/remote src); wordmark is the fallback when
                             $message is absent (preview/tests) or the image is off. --}}
                        <span style="white-space:nowrap;">
                            @isset($message)
                            <img src="{{ $message->embed(resource_path('mail/assets/giftlab-logo.png')) }}" width="34" height="34" alt="" style="display:inline-block; vertical-align:middle; margin-right:8px; border:0;">
                            @endisset
                            <span style="font-family:Georgia,'Times New Roman',serif; font-size:24px; font-weight:600; letter-spacing:-0.01em; color:#14141a; vertical-align:middle;">Gift<span style="color:#ff3b5f;">Lab</span></span>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <table role="presentation" class="gl-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border:1px solid #e6e6ef; border-radius:14px;">
                            <tr>
                                <td class="gl-px" style="padding:40px 48px 8px 48px;">
                                    <p class="gl-headline" style="margin:0 0 16px 0; font-family:Georgia,'Times New Roman',serif; font-size:26px; line-height:32px; color:#14141a; font-weight:400;">
                                        @if($hasProof)
                                            Your quote &amp; proof are ready to review
                                        @else
                                            Your quote is ready to review
                                        @endif
                                    </p>
                                    <p style="margin:0 0 24px 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#5b5b6b;">
                                        @if($greetingName)
                                            Hi {{ \Illuminate\Support\Str::before($greetingName, ' ') }},
                                        @else
                                            Hi there,
                                        @endif
                                        <br>
                                        @if($hasProof)
                                            Great news — we've put together your quote and a proof for your review. Take a look below and let us know if it's good to go.
                                        @else
                                            Great news — your quote is ready for review. Take a look below and let us know if it's good to go.
                                        @endif
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <td class="gl-px" style="padding:0 48px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e6e6ef; border-bottom:1px solid #e6e6ef;">
                                        <tr>
                                            {{-- The single order id used everywhere: this reference is what the
                                                 order pages show, what tracking and the QR code resolve to, and the
                                                 only identifier the order search can find. (Feature B collapsed the
                                                 former separate tracking code into this one reference.) --}}
                                            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; border-bottom:1px solid #f0f0f6;">Quote ref</td>
                                            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; border-bottom:1px solid #f0f0f6; font-weight:600;">{{ $quote->reference }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; border-bottom:1px solid #f0f0f6;">Items</td>
                                            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; border-bottom:1px solid #f0f0f6;">{{ $quote->lineItems->count() }} item(s), {{ $quote->lineItems->sum('qty') }} unit(s)</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; border-bottom:1px solid #f0f0f6;">Needed by</td>
                                            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; border-bottom:1px solid #f0f0f6;">{{ optional($quote->needed_by)->format('j M Y') ?? '—' }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Total</td>
                                            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:16px; color:#ff3b5f; font-weight:700;">S${{ number_format((float) $quote->total, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td class="gl-px" style="padding:28px 48px 8px 48px;" align="center">
                                    @if(!empty($proofItems))
                                        {{-- Batched round: one row per item, product name + its own thumbnail. --}}
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach($proofItems as $item)
                                                <tr>
                                                    <td style="padding:0 0 20px 0;" align="center">
                                                        <p style="margin:0 0 10px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; color:#14141a;">{{ $item['product_name'] }}</p>
                                                        @if($item['thumbnail_url'])
                                                            <img src="{{ $item['thumbnail_url'] }}" alt="{{ $item['product_name'] }} proof" class="gl-proof-img" width="504" style="display:block; margin:0 auto; width:100%; max-width:504px; height:auto; border:1px solid #e6e6ef; border-radius:10px;">
                                                        @else
                                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px dashed #cfcfdd; border-radius:10px;">
                                                                <tr>
                                                                    <td align="center" style="padding:36px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; letter-spacing:1px; color:#8a8a99; text-transform:uppercase;">
                                                                        Proof preview
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @elseif($hasProof && $proofImageUrl)
                                        <img src="{{ $proofImageUrl }}" alt="Proof preview" class="gl-proof-img" width="504" style="display:block; width:100%; max-width:504px; height:auto; border:1px solid #e6e6ef; border-radius:10px;">
                                    @else
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px dashed #cfcfdd; border-radius:10px;">
                                            <tr>
                                                <td align="center" style="padding:36px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; letter-spacing:1px; color:#8a8a99; text-transform:uppercase;">
                                                    Proof preview
                                                </td>
                                            </tr>
                                        </table>
                                    @endif
                                </td>
                            </tr>

                            <tr>
                                <td class="gl-px" align="center" style="padding:32px 48px 44px 48px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td class="gl-btn-td" align="center" bgcolor="#ff3b5f" style="border-radius:8px; background-color:#ff3b5f; padding:14px 32px;">
                                                <a href="{{ $quoteUrl }}" class="gl-btn" style="display:inline-block; font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none;">Review &amp; approve</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:28px 24px 0 24px;">
                        <p style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:20px; color:#8a8a99;">
                            Gift Lab &middot; Sign in to review and approve your quote.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
