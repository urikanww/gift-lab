{{--
    Staff-facing "NinjaVan reported this parcel returned/failed" email.
    Structure, palette and spacing come from mail/layouts/shell so it can
    never drift from the buyer emails. No greeting name - internal team, not
    a person.
--}}
@include('mail.layouts.shell', [
    'heading' => 'A parcel needs staff attention',
    'preheader' => 'Order '.($quote->reference ?? "job #{$job->id}").' — NinjaVan reported: '.$lastCourierStatus,
    'ctaUrl' => $orderUrl,
    'ctaLabel' => $orderUrl ? 'Open the order' : null,
    'footer' => 'Gift Lab · Internal notification.',

    'body' => 'NinjaVan reported <strong>'.e($lastCourierStatus).'</strong> for order '
        .'<strong>'.e($quote->reference ?? "job #{$job->id}").'</strong>. The job stays SHIPPED until '
        .'staff resolve it — close (write off), reship (re-queue for a fresh shipment), or cancel with credit.',

    'rows' => '
        <tr>
            <td style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Order ref</td>
            <td align="right" style="padding:14px 0; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a; font-weight:600;">'.e($quote->reference ?? '—').'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Consignment ref</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'.e($consignmentRef ?? '—').'</td>
        </tr>
        <tr>
            <td style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:13px; color:#8a8a99;">Courier status</td>
            <td align="right" style="padding:14px 0; border-top:1px solid #f0f0f6; font-family:Helvetica,Arial,sans-serif; font-size:14px; color:#14141a;">'.e($lastCourierStatus ?? '—').'</td>
        </tr>',
])
