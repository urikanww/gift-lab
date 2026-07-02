<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Models\PricingConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * IP/trademark screen for ingested 3D models. A CC licence clears copyright
 * on the model file but NOT trademarks — Thingiverse is full of branded
 * characters we must never sell. Two layers:
 *
 *  1. Keyword blocklist (pricing_configs catalogue/ip_blocklist) — free,
 *     always on, admin-editable.
 *  2. LLM screen — provider selected by IP_SCREEN_PROVIDER (anthropic,
 *     openai, or ollama); skipped when the provider's credentials/host are
 *     not configured. The task is a trivial yes/no classification, so any
 *     cheap model works; pick by which account you have.
 *
 * An LLM/API failure fails OPEN (item not flagged) with a warning logged —
 * the admin gate and spot checks remain the human backstop.
 */
final class IpScreenService
{
    /**
     * @return array{flagged: bool, reason: string|null}
     */
    public function screen(string $name, ?string $description): array
    {
        $haystack = Str::lower($name.' '.(string) $description);

        foreach ((array) PricingConfig::value('catalogue', 'ip_blocklist', []) as $term) {
            $term = Str::lower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return ['flagged' => true, 'reason' => "blocklist:{$term}"];
            }
        }

        return $this->llmScreen($name, $description);
    }

    /**
     * @return array{flagged: bool, reason: string|null}
     */
    private function llmScreen(string $name, ?string $description): array
    {
        $provider = strtolower((string) config('services.ip_screen.provider', 'anthropic'));
        $prompt = $this->prompt($name, $description);

        try {
            $text = match ($provider) {
                'anthropic' => $this->askAnthropic($prompt),
                'openai' => $this->askOpenai($prompt),
                'ollama' => $this->askOllama($prompt),
                default => $this->unknownProvider($provider),
            };
        } catch (Throwable $e) {
            Log::warning('IP screen failed (transport error) — item passes unflagged.', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return ['flagged' => false, 'reason' => null];
        }

        // Null = provider not configured / unavailable; blocklist-only mode.
        if ($text === null) {
            return ['flagged' => false, 'reason' => null];
        }

        return $this->parseVerdict($text);
    }

    private function prompt(string $name, ?string $description): string
    {
        return <<<PROMPT
        You screen 3D-print models for a corporate gift shop. Reply with ONLY a JSON object, no other text:
        {"ip_flag": boolean, "reason": string}

        ip_flag is true if the item likely depicts or references trademarked/branded IP
        (characters, franchises, logos, company products) that a shop must not sell.
        Generic functional/decorative items are false.

        Name: {$name}
        Description: {$description}
        PROMPT;
    }

    private function askAnthropic(string $prompt): ?string
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            return null;
        }

        $response = $this->request()
            ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => 200,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if (! $response->successful()) {
            Log::warning('IP screen failed — item passes unflagged.', ['provider' => 'anthropic', 'status' => $response->status()]);

            return null;
        }

        return (string) ($response->json('content.0.text') ?? '');
    }

    private function askOpenai(string $prompt): ?string
    {
        $key = (string) config('services.openai.key');
        if ($key === '') {
            return null;
        }

        $response = $this->request()
            ->withToken($key)
            ->post(rtrim((string) config('services.openai.base_url'), '/').'/chat/completions', [
                'model' => (string) config('services.openai.model'),
                'max_tokens' => 200,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if (! $response->successful()) {
            Log::warning('IP screen failed — item passes unflagged.', ['provider' => 'openai', 'status' => $response->status()]);

            return null;
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    private function askOllama(string $prompt): ?string
    {
        $base = (string) config('services.ollama.base_url');
        if ($base === '') {
            return null;
        }

        $response = $this->request()
            ->post(rtrim($base, '/').'/api/chat', [
                'model' => (string) config('services.ollama.model'),
                'stream' => false,
                'format' => 'json',
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if (! $response->successful()) {
            Log::warning('IP screen failed — item passes unflagged.', ['provider' => 'ollama', 'status' => $response->status()]);

            return null;
        }

        return (string) ($response->json('message.content') ?? '');
    }

    private function unknownProvider(string $provider): ?string
    {
        Log::warning('IP screen: unknown IP_SCREEN_PROVIDER — blocklist-only mode.', ['provider' => $provider]);

        return null;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 500, throw: false);
    }

    /**
     * @return array{flagged: bool, reason: string|null}
     */
    private function parseVerdict(string $text): array
    {
        $verdict = json_decode(trim($text), true);

        if (! is_array($verdict) || ! array_key_exists('ip_flag', $verdict)) {
            Log::warning('IP screen returned unparseable verdict — item passes unflagged.', ['text' => mb_substr($text, 0, 200)]);

            return ['flagged' => false, 'reason' => null];
        }

        return [
            'flagged' => (bool) $verdict['ip_flag'],
            'reason' => isset($verdict['reason']) ? mb_substr((string) $verdict['reason'], 0, 200) : null,
        ];
    }
}
