{{--
    Staff-facing "an unhandled exception occurred" alert. Structure, palette
    and spacing come from mail/layouts/shell so it can never drift from the
    buyer emails. No CTA - there is no admin exceptions screen yet, so this
    is informational only.
--}}
@include('mail.layouts.shell', [
    'heading' => 'An unhandled exception occurred',
    'preheader' => $exceptionClass.' was thrown and reported to the team.',
    'footer' => 'Gift Lab · Internal notification.',

    'body' => 'An unexpected error occurred and was reported to the team. '
        .'Type: <strong>'.e($exceptionClass).'</strong>.',

    'rows' => '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Type</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.e($exceptionClass).'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; vertical-align:top;">Message</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; line-height:21px;">'.nl2br(e($exceptionMessage)).'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Request path</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'.e($path ?? '—').'</td>
        </tr>',
])
