<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateShippingAddressRequest;
use App\Models\Quote;
use App\Models\ShippingAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingAddressController extends Controller
{
    public function show(Request $request, Quote $quote): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json([
            'data' => $quote->shippingAddressOrDefault(),
            'saved' => $quote->shippingAddress !== null,
        ]);
    }

    public function update(UpdateShippingAddressRequest $request, Quote $quote): JsonResponse
    {
        $address = ShippingAddress::updateOrCreate(
            ['quote_id' => $quote->id],
            $request->validated(),
        );

        return response()->json([
            'data' => $address->only([
                'recipient_name', 'phone', 'email', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'notes',
            ]),
            'saved' => true,
        ]);
    }
}
