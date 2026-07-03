<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PublishState;
use App\Http\Requests\LeadTimeEstimateRequest;
use App\Models\Product;
use App\Services\LeadTimeService;
use Illuminate\Http\JsonResponse;

/**
 * Public, no-account delivery-window estimate for the designer/cart (spec 6.1).
 * Only PUBLISHED products are estimated so the endpoint can't leak drafts.
 */
class LeadTimeEstimateController extends Controller
{
    public function __construct(private readonly LeadTimeService $lead)
    {
    }

    public function __invoke(LeadTimeEstimateRequest $request): JsonResponse
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $spec): int => (int) $spec['product_id'],
            $request->array('line_items'),
        )));

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('publish_state', PublishState::Published)
            ->get();

        if ($products->count() !== count($ids)) {
            return response()->json(['message' => 'One or more products are unavailable.'], 422);
        }

        $classes = $products->map(fn (Product $p) => $p->class)->all();

        return response()->json($this->lead->estimate($classes));
    }
}
