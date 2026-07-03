<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBrandKitRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-company brand kit (saved logo + brand colours). Scoped to the caller's
 * own company — a buyer never sees or edits another company's kit.
 */
class BrandKitController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $this->companyFor($request);

        if ($company === null) {
            return response()->json(['message' => 'No company on this account.'], 404);
        }

        return response()->json($this->payload($company));
    }

    public function update(UpdateBrandKitRequest $request): JsonResponse
    {
        $company = $this->companyFor($request);

        if ($company === null) {
            return response()->json(['message' => 'No company on this account.'], 404);
        }

        $company->brand_colors = $request->input('colors', []);

        // Only touch the logo when the key is present, so a colours-only save
        // doesn't wipe the stored logo; an explicit null clears it.
        if ($request->exists('logo')) {
            $company->brand_logo = $request->input('logo');
        }

        $company->save();

        return response()->json($this->payload($company));
    }

    private function companyFor(Request $request): ?Company
    {
        $companyId = $request->user()?->company_id;

        return $companyId !== null ? Company::find($companyId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Company $company): array
    {
        return [
            'colors' => $company->brand_colors ?? [],
            'logo' => $company->brand_logo,
            'has_logo' => ! empty($company->brand_logo),
        ];
    }
}
