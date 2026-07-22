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
use Illuminate\Http\Request;

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

        // The resource exposes quote_reference off the quote relation; we already
        // hold the quote, so hand it over rather than re-fetching the same row.
        $proof->setRelation('quote', $quote);

        return (new ProofResource($proof))->response()->setStatusCode(201);
    }

    public function decide(DecideProofRequest $request, Proof $proof): ProofResource
    {
        $proof = $request->string('decision')->toString() === 'approve'
            ? $this->quotes->approveProof($proof)
            : $this->quotes->requestProofChanges(
                $proof,
                $request->input('notes'),
                $request->input('attachments', []),
            );

        return new ProofResource($proof->loadMissing('quote'));
    }

    /**
     * Re-send the buyer's proof-review email for an open proof. Staff-only - a
     * buyer never resends their own. The route also carries permission:quotes.edit,
     * which refines WHICH staff_admin may; this floor stops a buyer, whom that
     * middleware lets through.
     */
    public function resend(Request $request, Proof $proof): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $this->quotes->resendProof($proof);

        return response()->json(['message' => 'Proof email resent.']);
    }
}
