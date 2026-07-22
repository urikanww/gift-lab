{{--
    Staff-facing "buyer requested changes" email. Structure, palette and spacing
    come from mail/layouts/shell so it can never drift from the buyer emails.

    Content only below. Unlike the buyer emails there is no greeting name - this
    goes to the internal team, not a person.
--}}
@include('mail.layouts.shell', [
    'heading' => 'A buyer requested changes',
    'preheader' => 'Order '.$quote->reference.' — the buyer sent proof v'.$proof->version.' back for changes.',
    'ctaUrl' => $orderUrl,
    'ctaLabel' => 'Open the order',
    'footer' => 'Gift Lab · Internal notification.',

    'body' => 'The buyer sent proof <strong>v'.e($proof->version).'</strong> back on order '
        .'<strong>'.e($quote->reference).'</strong> for changes. Review their note and issue a revised proof.',

    'rows' => '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Order ref</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.e($quote->reference).'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99; vertical-align:top;">What to change</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; line-height:21px;">'
                .($notes ? nl2br(e($notes)) : '<span style="color:#8a8a99;">No note provided.</span>').'</td>
        </tr>',
])
