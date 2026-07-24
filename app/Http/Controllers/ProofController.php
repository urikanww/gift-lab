<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DecideProofRequest;
use App\Http\Requests\StoreProofRequest;
use App\Http\Resources\ProofResource;
use App\Models\LineItem;
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
    public function __construct(private readonly QuoteService $quotes) {}

    /**
     * Stage artwork for one line as a DRAFT proof. The round is not sent to the
     * buyer until send() flips every staged draft in one email.
     */
    public function stage(StoreProofRequest $request, Quote $quote, LineItem $lineItem): JsonResponse
    {
        abort_unless($lineItem->quote_id === $quote->id, 404);

        $proof = $this->quotes->stageProof($quote, $lineItem, $request->string('artwork_version_ref')->toString());

        // The resource exposes quote_reference off the quote relation; we already
        // hold the quote, so hand it over rather than re-fetching the same row.
        $proof->setRelation('quote', $quote);

        return (new ProofResource($proof))->response()->setStatusCode(201);
    }

    /**
     * Send the staged round: flip every DRAFT proof to SENT, move the order into
     * PROOFING, and email the buyer once. Staff-only - the route also carries
     * permission:quotes.edit, this floor stops a buyer that middleware lets by.
     */
    public function send(Request $request, Quote $quote): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $this->quotes->sendProofs($quote);

        return response()->json(['message' => 'Proofs sent to the buyer.']);
    }

    /**
     * Buyer signs off every line still awaiting them (SENT) in one action.
     * CHANGES_REQUESTED lines are left alone.
     */
    public function approveAll(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('view', $quote);

        $this->quotes->approveAllOpenProofs($quote, $request->user());

        return response()->json(['message' => 'Approved.']);
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
