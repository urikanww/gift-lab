<?php

declare(strict_types=1);

use App\Models\LineItem;

it('needs a proof for a customized line', function (): void {
    $line = new LineItem(['customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png']]);
    expect($line->needsProof())->toBeTrue();
});

it('needs a proof for a buyer-uploaded finished-look line', function (): void {
    $line = new LineItem(['customization' => ['mode' => 'buyer_uploaded', 'artwork_ref' => 'artwork/y.png']]);
    expect($line->needsProof())->toBeTrue();
});

it('needs no proof for a plain stock line', function (): void {
    expect((new LineItem(['customization' => null]))->needsProof())->toBeFalse();
    expect((new LineItem(['customization' => []]))->needsProof())->toBeFalse();
});

it('needs a proof for an artwork-only line with no mode set', function (): void {
    $line = new LineItem(['customization' => ['artwork_ref' => 'artwork/x.png']]);
    expect($line->needsProof())->toBeTrue();
});

it('needs a proof for a line with reference images but no mode or artwork', function (): void {
    $line = new LineItem(['customization' => ['reference_refs' => ['refs/a.png', 'refs/b.png']]]);
    expect($line->needsProof())->toBeTrue();
});

it('needs no proof for a bare line with no mode, artwork, or reference refs', function (): void {
    $line = new LineItem(['customization' => ['note' => 'just a comment', 'reference_refs' => []]]);
    expect($line->needsProof())->toBeFalse();
});

it('needs no proof for a dropped line even with an artwork ref', function (): void {
    $line = new LineItem([
        'customization' => ['artwork_ref' => 'artwork/z.png'],
        'line_state' => 'DROPPED',
    ]);
    expect($line->needsProof())->toBeFalse();
});
