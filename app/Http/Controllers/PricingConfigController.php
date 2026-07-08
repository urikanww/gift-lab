<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PricingConfig;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superadmin pricing/config editor (spec 6.2 / audit E1, D7, E2): every value
 * the quote engine reads at quote time - margins, floor, fees, size surcharges,
 * print costs, thresholds, drift %, pay-now cutoff - is editable here without
 * a deploy or DB access. Every change is audit-logged (who/old/new).
 */
class PricingConfigController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        $configs = PricingConfig::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(fn (PricingConfig $c): array => [
                'id' => $c->id,
                'group' => $c->group,
                'key' => $c->key,
                'value' => $c->value,
                'label' => $c->label,
                'is_money' => $c->is_money,
                'currency' => $c->currency,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $configs]);
    }

    public function update(Request $request, PricingConfig $pricingConfig): JsonResponse
    {
        abort_unless($request->user()->isSuperadmin(), 403);

        // `present` (not `required`) so falsy-but-valid values - 0, false, an
        // empty list - are accepted; group/key are immutable identifiers.
        $request->validate(['value' => ['present']]);

        $old = $pricingConfig->value;
        $pricingConfig->value = $request->input('value');
        $pricingConfig->updated_by = $request->user()->id;
        $pricingConfig->save();

        $this->audit->log($pricingConfig, 'pricing_config.updated', [
            'group' => $pricingConfig->group,
            'key' => $pricingConfig->key,
            'value' => $old,
        ], [
            'value' => $pricingConfig->value,
        ]);

        return response()->json([
            'data' => [
                'id' => $pricingConfig->id,
                'group' => $pricingConfig->group,
                'key' => $pricingConfig->key,
                'value' => $pricingConfig->value,
                'label' => $pricingConfig->label,
                'is_money' => $pricingConfig->is_money,
                'currency' => $pricingConfig->currency,
            ],
        ]);
    }
}
