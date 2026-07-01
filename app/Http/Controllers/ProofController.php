<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DecideProofRequest;
use App\Http\Requests\StoreProofRequest;
use App\Http\Resources\ProofResource;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;

/**
 * Proof issuance + immutable buyer sign-off (gate 1). Staff issues; buyer
 * approves or requests changes. Approval is recorded who/what-version/when and
 * cannot be mutated afterward.
 */
class ProofController extends Controller
{
    public function __construct(private readonly QuoteService $quotes)
    {
    }

    public function store(StoreProofRequest $request, Quote $quote): JsonResponse
    {
        $proof = $this->quotes->issueProof(
            $quote,
            $request->string('artwork_version_ref')->toString(),
            $request->input('notes'),
        );

        return (new ProofResource($proof))->response()->setStatusCode(201);
    }

    public function decide(DecideProofRequest $request, Proof $proof): ProofResource
    {
        $proof = $request->string('decision')->toString() === 'approve'
            ? $this->quotes->approveProof($proof)
            : $this->quotes->requestProofChanges($proof, $request->input('notes'));

        return new ProofResource($proof);
    }
}
