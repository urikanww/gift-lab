{{--
    Buyer-facing milestone email. Structure, palette and spacing come from
    mail/layouts/shell so this cannot drift from the quote-ready email again -
    it was first written standalone and immediately did: neutral greys and a
    black button against the warm cream and purple everything else uses.

    Content only below.
--}}
@php
    // Shipped only: consignmentRef/trackingUrl are null on every other
    // milestone, so this row is absent everywhere else. When present, the ref
    // is always shown as text ("Tracking: {ref}"); the "Track your parcel"
    // link is added only when a tracking URL exists - Carrier::Other has no
    // self-serve tracking page, so it renders the ref as plain text with no
    // href.
    $trackingRow = '';
    if (($consignmentRef ?? null) !== null) {
        $trackingValue = 'Tracking: '.e($consignmentRef);
        if (($trackingUrl ?? null) !== null) {
            $trackingValue .= '<br><a href="'.e($trackingUrl).'" style="font-size:13px; font-weight:600; color:#ff3b5f; text-decoration:none;">Track your parcel</a>';
        }
        $trackingRow = '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Tracking</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.$trackingValue.'</td>
        </tr>';
    }
@endphp
@include('mail.layouts.shell', [
    'heading' => $heading,
    'preheader' => $body,
    'ctaUrl' => $quoteUrl,
    'ctaLabel' => $ctaLabel,
    'footer' => 'Gift Lab · Just reply to this email if you need us.',

    'body' => ($greetingName
        ? 'Hi '.e(\Illuminate\Support\Str::before($greetingName, ' ')).',<br>'
        : 'Hi there,<br>')
        .e($body),

    {{-- Same reference the order page shows and the only one order search
         finds. Kept minimal: a milestone note is not a quote summary, and
         repeating the whole order here would bury the one line that matters. --}}
    'rows' => '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Order ref</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.e($quote->reference).'</td>
        </tr>'.$trackingRow,
])
