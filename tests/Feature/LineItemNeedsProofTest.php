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
