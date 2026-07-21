<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $heading }}</title>
<style>
    @media only screen and (max-width: 620px) {
        .gl-wrapper { width: 100% !important; }
        .gl-card { width: 100% !important; border-radius: 0 !important; }
        .gl-px { padding-left: 20px !important; padding-right: 20px !important; }
        .gl-btn { display: block !important; width: 100% !important; text-align: center !important; }
        .gl-headline { font-size: 22px !important; line-height: 28px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background-color:#f4f1ec; -webkit-text-size-adjust:100%;">
{{-- Preheader: the line mail clients show beside the subject. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f4f1ec;">
    {{ $body }}
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f1ec;">
    <tr>
        <td align="center" style="padding:40px 16px;">
            <table role="presentation" class="gl-wrapper" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                <tr>
                    <td class="gl-card" style="background-color:#ffffff; border-radius:12px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td class="gl-px" style="padding:32px 40px 8px 40px;">
                                    <h1 class="gl-headline" style="margin:0; font-family:Georgia,'Times New Roman',serif; font-size:26px; line-height:32px; color:#1a1a1a; font-weight:normal;">
                                        {{ $heading }}
                                    </h1>
                                </td>
                            </tr>
                            <tr>
                                <td class="gl-px" style="padding:16px 40px 0 40px; font-family:Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4a4a4a;">
                                    @if ($greetingName)
                                        <p style="margin:0 0 16px 0;">Hi {{ $greetingName }},</p>
                                    @endif
                                    <p style="margin:0 0 16px 0;">{{ $body }}</p>
                                </td>
                            </tr>
                            <tr>
                                <td class="gl-px" style="padding:16px 40px 8px 40px;">
                                    <a href="{{ $quoteUrl }}" class="gl-btn" style="display:inline-block; padding:12px 24px; background-color:#1a1a1a; color:#ffffff; text-decoration:none; border-radius:6px; font-family:Helvetica,Arial,sans-serif; font-size:15px;">
                                        View your order
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td class="gl-px" style="padding:8px 40px 32px 40px; font-family:Helvetica,Arial,sans-serif; font-size:13px; line-height:20px; color:#8a8a8a;">
                                    <p style="margin:0;">Order reference {{ $quote->reference }}</p>
                                    {{-- Replies reach a monitored inbox; see OrderMilestoneMail. --}}
                                    <p style="margin:8px 0 0 0;">Just reply to this email if you need us.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
