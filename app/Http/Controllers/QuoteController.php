<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AmendQuoteRequest;
use App\Http\Requests\IssuePurchaseOrderRequest;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Quote spine HTTP surface. Buyers see only their company's quotes; staff act
 * on any. All mutations route through QuoteService, which guards the state
 * machine and broadcasts over Reverb.
 */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $quotes = Quote::query()
            ->when(! $user->isStaff(), fn ($q) => $q->where('company_id', $user->company_id))
            ->latest()
            ->paginate(20);

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $quote = $this->quotes->create(
            (int) $request->integer('company_id'),
            $request->array('line_items'),
            $request->input('notes'),
        );

        return (new QuoteResource($quote->load('lineItems')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('view', $quote);

        return new QuoteResource($quote->load(['lineItems.product', 'proofs']));
    }

    public function amend(AmendQuoteRequest $request, Quote $quote): QuoteResource
    {
        $quote = $this->quotes->amend(
            $quote,
            $request->array('lines'),
            $request->input('delivery') !== null ? (float) $request->input('delivery') : null,
            $request->input('notes'),
        );

        return new QuoteResource($quote->load('lineItems'));
    }

    public function send(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('manageProduction', $quote);

        return new QuoteResource($this->quotes->send($quote));
    }

    public function accept(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);

        return new QuoteResource($this->quotes->accept($quote));
    }

    public function issuePurchaseOrder(IssuePurchaseOrderRequest $request, Quote $quote): JsonResponse
    {
        $po = $this->quotes->issuePurchaseOrder(
            $quote,
            $request->string('po_ref')->toString(),
            $request->input('invoice_ref'),
            $request->input('terms'),
        );

        return response()->json([
            'purchase_order' => [
                'id' => $po->id,
                'po_ref' => $po->po_ref,
                'invoice_ref' => $po->invoice_ref,
                'amount' => $po->amount,
                'currency' => $po->currency,
                'payment_state' => $po->payment_state->value,
            ],
            'quote' => new QuoteResource($quote->fresh()),
        ], 201);
    }

    public function procure(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('manageProduction', $quote);

        return new QuoteResource($this->quotes->procure($quote));
    }

    public function cancel(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);

        return new QuoteResource($this->quotes->cancel($quote, $request->input('reason')));
    }
}
