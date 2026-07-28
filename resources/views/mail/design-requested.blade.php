{{--
    Staff-facing "a buyer asked us to design this" email. Structure, palette and
    spacing come from mail/layouts/shell so it can never drift from the buyer
    emails. Content only below - this goes to the internal team, not a person, so
    there is no greeting name.
--}}
@include('mail.layouts.shell', [
    'heading' => 'A buyer asked us to design their order',
    'preheader' => 'Order '.$quote->reference.' — '.$lineCount.' '.\Illuminate\Support\Str::plural('item', $lineCount).' need artwork from the team.',
    'ctaUrl' => $orderUrl,
    'ctaLabel' => 'Open the order',
    'footer' => 'Gift Lab · Internal notification.',

    'body' => 'On order <strong>'.e($quote->reference).'</strong> the buyer chose <strong>"Upload finished look"</strong> — '
        .'they sent reference images and placement notes instead of laying out the design themselves. '
        .'Open the order, review their brief, and stage the first proof for them to approve.',

    'rows' => $rows,
])
