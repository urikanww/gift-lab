{{--
    Shared shell for buyer-facing email: background, wordmark, card and footer.

    Extracted because the milestone email was first built standalone and drifted
    straight away - neutral greys and a black button against the warm cream card
    and purple accent everything else uses. Anything structural lives here now,
    so the next email cannot drift the same way.

    THE PALETTE. Email clients have no CSS variables, so these values are
    repeated inline throughout; this list is the reference.

      #f4f1ec  page background (warm cream)
      #fffdf8  card background (off-white)
      #e8e1d3  card border
      #ece5d6  section rule
      #f1ebdd  row rule
      #2b2620  headline / strong value
      #4a4438  body text
      #8a7f6a  wordmark, labels
      #a89b7d  footer, secondary muted
      #6b4de6  accent: CTA fill, emphasised total

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
<body style="margin:0; padding:0; background-color:#f4f1ec; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
{{-- Preheader: the line clients show beside the subject in the inbox list. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f4f1ec;">
    {{ $preheader ?? $heading }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f1ec;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" class="gl-wrapper" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                <tr>
                    <td align="center" style="padding-bottom:24px;">
                        <span style="font-family:Georgia,'Times New Roman',serif; font-size:13px; letter-spacing:4px; color:#8a7f6a; text-transform:uppercase;">GIFT LAB</span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <table role="presentation" class="gl-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fffdf8; border:1px solid #e8e1d3; border-radius:14px;">
                            <tr>
                                <td class="gl-px" style="padding:40px 48px 8px 48px;">
                                    <p class="gl-headline" style="margin:0 0 16px 0; font-family:Georgia,'Times New Roman',serif; font-size:26px; line-height:32px; color:#2b2620; font-weight:400;">
                                        {{ $heading }}
                                    </p>
                                    <p style="margin:0 0 24px 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:24px; color:#4a4438;">
                                        {!! $body !!}
                                    </p>
                                </td>
                            </tr>

                            @isset($rows)
                            <tr>
                                <td class="gl-px" style="padding:0 48px;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #ece5d6; border-bottom:1px solid #ece5d6;">
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
                                            <td class="gl-btn-td" align="center" bgcolor="#6b4de6" style="border-radius:8px; background-color:#6b4de6; padding:14px 32px;">
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
                        <p style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:12px; line-height:20px; color:#a89b7d;">
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
