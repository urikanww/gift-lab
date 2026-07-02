<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Models\PricingConfig;
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
 *  2. Claude screen (when ANTHROPIC_API_KEY is set) — catches what keywords
 *     miss ("pocket monster figurine").
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
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            return ['flagged' => false, 'reason' => null];
        }

        $prompt = <<<PROMPT
        You screen 3D-print models for a corporate gift shop. Reply with ONLY a JSON object, no other text:
        {"ip_flag": boolean, "reason": string}

        ip_flag is true if the item likely depicts or references trademarked/branded IP
        (characters, franchises, logos, company products) that a shop must not sell.
        Generic functional/decorative items are false.

        Name: {$name}
        Description: {$description}
        PROMPT;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])
                ->connectTimeout(5)
                ->timeout(30)
                ->retry(2, 500, throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => (string) config('services.anthropic.model'),
                    'max_tokens' => 200,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (Throwable $e) {
            Log::warning('IP screen failed (transport error) — item passes unflagged.', ['error' => $e->getMessage()]);

            return ['flagged' => false, 'reason' => null];
        }

        if (! $response->successful()) {
            Log::warning('IP screen failed — item passes unflagged.', ['status' => $response->status()]);

            return ['flagged' => false, 'reason' => null];
        }

        $text = (string) ($response->json('content.0.text') ?? '');
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
