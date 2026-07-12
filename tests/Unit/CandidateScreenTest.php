<?php

declare(strict_types=1);

use App\Services\Catalogue\CandidateScreen;

it('flags known IP/branded names', function (): void {
    $s = new CandidateScreen();
    expect($s->ipFlag('Disney Frozen Ceramic Mug'))->toBe('disney');
    expect($s->ipFlag('Sanrio Hello Kitty Tumbler'))->toBe('sanrio');
    expect($s->ipFlag('Plain Ceramic Mug 440ml'))->toBeNull();
});

it('flags likely non-UV materials', function (): void {
    $s = new CandidateScreen();
    expect($s->materialFlag('Cotton Canvas Tote Bag'))->toBe('fabric');
    expect($s->materialFlag('Plush Teddy Bear'))->toBe('plush');
    expect($s->materialFlag('Ceramic Coffee Mug'))->toBeNull();
});
