<?php

declare(strict_types=1);

use App\Models\PricingConfig;
use App\Services\Model3d\IpScreenService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    seedPricing();
    $this->screen = app(IpScreenService::class);
});

it('flags a blocklisted trademark term without any API call', function (): void {
    Http::fake();

    $verdict = $this->screen->screen('Pikachu desk holder', null);

    expect($verdict['flagged'])->toBeTrue()
        ->and($verdict['reason'])->toBe('blocklist:pikachu');
    Http::assertNothingSent();
});

it('passes a clean item when no LLM key is configured', function (): void {
    config()->set('services.anthropic.key', '');

    expect($this->screen->screen('Hexagon pen holder', 'A pen holder.')['flagged'])->toBeFalse();
});

it('flags an item the LLM identifies as branded IP', function (): void {
    config()->set('services.anthropic.key', 'sk-test');
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"ip_flag": true, "reason": "depicts a franchise character"}']],
        ], 200),
    ]);

    $verdict = $this->screen->screen('Pocket monster figurine', 'Cute electric mouse.');

    expect($verdict['flagged'])->toBeTrue()
        ->and($verdict['reason'])->toBe('depicts a franchise character');
});

it('fails open with a warning when the LLM call errors', function (): void {
    config()->set('services.anthropic.key', 'sk-test');
    Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);

    expect($this->screen->screen('Plain vase', null)['flagged'])->toBeFalse();
});
