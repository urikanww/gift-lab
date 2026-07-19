<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavedAddressRequest;
use App\Http\Requests\UpdateSavedAddressRequest;
use App\Models\SavedAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedAddressController extends Controller
{
    private const MAX_PER_USER = 3;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->savedAddresses()->latest()->get(),
        ]);
    }

    public function store(StoreSavedAddressRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_if(
            $user->savedAddresses()->count() >= self::MAX_PER_USER,
            422,
            'You can save at most '.self::MAX_PER_USER.' addresses.',
        );

        $address = $user->savedAddresses()->create($request->validated());

        return response()->json(['data' => $address], 201);
    }

    public function update(UpdateSavedAddressRequest $request, SavedAddress $savedAddress): JsonResponse
    {
        $this->authorize('update', $savedAddress);

        $savedAddress->update($request->validated());

        return response()->json(['data' => $savedAddress]);
    }

    public function destroy(Request $request, SavedAddress $savedAddress): JsonResponse
    {
        $this->authorize('delete', $savedAddress);

        $savedAddress->delete();

        return response()->json(['data' => true]);
    }
}
