{{--
    Shared shell for buyer-facing email: background, wordmark, card and footer.

    Extracted because the milestone email was first built standalone and drifted
    straight away. It later drifted a second time on COLOUR: a warm cream/brown
    card and a purple CTA, against the app's actual cool-neutral surfaces and
    coral (#ff3b5f "Bold Studio coral") brand. These values now MIRROR the app's
    light-theme tokens in frontend/src/index.css, so the email reads as the same
    product. Anything structural lives here so the next email cannot drift again.

    THE PALETTE - the app's light tokens, inlined (email clients have no CSS
    variables). Keep in step with index.css :root if those ever move.

      #f6f6fb  page background   (--color-bg, cool paper)
      #ffffff  card background   (--color-surface)
      #e6e6ef  card / section rule (--color-border)
      #f0f0f6  row rule          (--color-surface-2)
      #14141a  headline / strong (--color-fg)
      #5b5b6b  body text         (--color-fg-muted)
      #8a8a99  labels, footer    (--color-fg-subtle)
      #ff3b5f  brand coral: CTA fill, "Lab" wordmark, emphasised total (--color-primary)

    Wordmark: "GiftLab" with "Lab" in coral, serif display face (Georgia stands
    in for the app's Fraunces, which email can't reliably web-load).

    Slots: $heading, $body (html), optional $rows, $ctaUrl, $ctaLabel, $footer.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $heading }}</title>
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
        .gl-btn-td { display: block !important; width: 100% !important; }
        .gl-btn { display: block !important; width: 100% !important; text-align: center !important; }
        .gl-headline { font-size: 22px !important; line-height: 28px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background-color:#f6f6fb; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
{{-- Preheader: the line clients show beside the subject in the inbox list. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f6f6fb;">
    {{ $preheader ?? $heading }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f6fb;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" class="gl-wrapper" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                <tr>
                    <td align="center" style="padding-bottom:24px;">
                        {{-- Flask mark (mirrors the app LogoMark) CID-embedded so
                             it survives image-blocking that strips SVG/remote src.
                             $message exists only at send time; a plain view()
                             render (preview/tests) falls back to the wordmark. --}}
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
                                        {{ $heading }}
                                    </p>
                                    <p style="margin:0 0 24px 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#5b5b6b;">
                                        {!! $body !!}
                                    </p>
                                </td>
                            </tr>

                            @isset($rows)
                            <tr>
                                <td class="gl-px" style="padding:0 48px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #e6e6ef; border-bottom:1px solid #e6e6ef;">
                                        {!! $rows !!}
                                    </table>
                                </td>
                            </tr>
                            @endisset

                            @isset($ctaUrl)
                            <tr>
                                <td class="gl-px" align="center" style="padding:32px 48px 44px 48px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td class="gl-btn-td" align="center" bgcolor="#ff3b5f" style="border-radius:8px; background-color:#ff3b5f; padding:14px 32px;">
                                                <a href="{{ $ctaUrl }}" class="gl-btn" style="display:inline-block; font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none;">{{ $ctaLabel ?? 'View your order' }}</a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            @endisset
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:28px 24px 0 24px;">
                        <p style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:20px; color:#8a8a99;">
                            {{ $footer ?? 'Gift Lab · Just reply to this email if you need us.' }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
