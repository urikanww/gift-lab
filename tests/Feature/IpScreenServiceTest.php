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

it('screens via OpenAI when selected as provider', function (): void {
    config()->set('services.ip_screen.provider', 'openai');
    config()->set('services.openai.key', 'sk-openai-test');
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"ip_flag": true, "reason": "branded character"}']]],
        ], 200),
    ]);

    $verdict = $this->screen->screen('Famous plumber figurine', 'Jumping character.');

    expect($verdict['flagged'])->toBeTrue()
        ->and($verdict['reason'])->toBe('branded character');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.openai.com/v1/chat/completions')
        && $request->hasHeader('Authorization', 'Bearer sk-openai-test'));
});

it('screens via Ollama when selected as provider', function (): void {
    config()->set('services.ip_screen.provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://localhost:11434');
    Http::fake([
        'localhost:11434/*' => Http::response([
            'message' => ['content' => '{"ip_flag": false, "reason": "generic item"}'],
        ], 200),
    ]);

    expect($this->screen->screen('Hexagon planter', null)['flagged'])->toBeFalse();
});

it('runs blocklist-only when the selected provider has no credentials', function (): void {
    config()->set('services.ip_screen.provider', 'openai');
    config()->set('services.openai.key', '');
    Http::fake();

    expect($this->screen->screen('Plain pen holder', null)['flagged'])->toBeFalse();
    Http::assertNothingSent();
});

it('runs blocklist-only on an unknown provider', function (): void {
    config()->set('services.ip_screen.provider', 'geminix');
    Http::fake();

    expect($this->screen->screen('Plain pen holder', null)['flagged'])->toBeFalse();
    Http::assertNothingSent();
});
