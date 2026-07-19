<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AmendQuoteRequest;
use App\Http\Requests\CancelQuoteRequest;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\SendQuoteRequest;
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
            // Staff see all companies - load the name so the UI can label rows.
            ->when($user->isStaff(), fn ($q) => $q->with('company'))
            ->latest()
            ->paginate(20);

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request): JsonResponse
    {
        $companyId = (int) $request->integer('company_id');

        // Defense-in-depth: tenancy is validated in StoreQuoteRequest, but the
        // policy is an independent net so no future entry point can create a
        // cross-company quote by bypassing the FormRequest.
        $this->authorize('create', [Quote::class, $companyId]);

        $quote = $this->quotes->create(
            $companyId,
            $request->array('line_items'),
            $request->input('notes'),
            $request->input('needed_by'),
            $request->input('idempotency_key'),
            $request->input('shipping_address'),
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

    public function send(SendQuoteRequest $request, Quote $quote): QuoteResource
    {
        $this->authorize('manageProduction', $quote);

        return new QuoteResource($this->quotes->send(
            $quote,
            $request->input('artwork_version_ref'),
            $request->input('notes'),
        ));
    }

    public function accept(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('update', $quote);

        return new QuoteResource($this->quotes->accept($quote));
    }

    public function issueInvoice(IssueInvoiceRequest $request, Quote $quote): JsonResponse
    {
        $invoice = $this->quotes->issueInvoice(
            $quote,
            $request->string('po_ref')->toString(),
            $request->input('invoice_ref'),
            $request->input('terms'),
        );

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'po_ref' => $invoice->po_ref,
                'invoice_ref' => $invoice->invoice_ref,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'payment_state' => $invoice->payment_state->value,
            ],
            'quote' => new QuoteResource($quote->fresh()),
        ], 201);
    }

    public function procure(Request $request, Quote $quote): QuoteResource
    {
        $this->authorize('manageProduction', $quote);

        return new QuoteResource($this->quotes->procure($quote));
    }

    public function cancel(CancelQuoteRequest $request, Quote $quote): QuoteResource
    {
        $this->authorize('manageProduction', $quote);

        return new QuoteResource($this->quotes->cancel($quote, $request->input('reason')));
    }
}
