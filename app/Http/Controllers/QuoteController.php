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
            ->when($request->filled('q'), function ($query) use ($request): void {
                // ?q[]=abc arrives as an array; casting that to string is a TypeError
                // (a 500 on a public search box), so ignore anything not a string.
                $raw = $request->input('q');
                if (! is_string($raw)) {
                    return;
                }

                // A leading # is how the id has been written everywhere until now,
                // so buyers paste it verbatim - "#42" and "# 42" must both reach
                // the id branch, hence the trim on either side of the strip.
                $term = trim(ltrim(trim($raw), '#'));
                if ($term === '') {
                    return;
                }

                // Nested so the orWhere cannot escape the company_id scope above -
                // flat, a buyer could read another company's order by guessing an id.
                $query->where(function ($w) use ($term): void {
                    // Wildcards escaped so a stray % or _ narrows literally instead
                    // of broadening, matching AdminCatalogueController::index. Not a
                    // security boundary - the term is a bound parameter and the
                    // company scope already bounds what can match - but the app's
                    // two search endpoints should agree on what a % in the box means.
                    //
                    // The backslash only escapes on a connection whose default LIKE
                    // escape is backslash - true on MySQL, NOT on SQLite (which the
                    // test suite uses). That divergence is inert here because a
                    // reference is generated from an alphabet with no %, _ or \, so
                    // both engines match nothing either way. It stops being inert the
                    // moment this searches a free-text column (notes, company name):
                    // the SQLite suite would stay green while MySQL behaved
                    // differently. Add an explicit ESCAPE clause before that happens.
                    $w->where('reference', 'like', '%'.addcslashes($term, '%_\\').'%');
                    // Exact, and only for all-digit input: LIKE on an integer key
                    // matches 1 against 10/21/100 and forfeits the index.
                    if (ctype_digit($term)) {
                        $w->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return QuoteResource::collection($quotes);
    }

    /**
     * Buyer dashboard summary: order counts bucketed by lifecycle stage, scoped
     * to the caller's company, plus the short list of orders waiting on a buyer
     * decision (accept a sent quote, approve a proof, pay an invoice). Staff have
     * no company, so this returns zeros for them - they use the staff dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $base = Quote::query()->where('company_id', $companyId);

        // One grouped query, bucketed in PHP. Keys are the QuoteState string
        // values as stored on the column (e.g. 'SENT').
        $counts = (clone $base)
            ->selectRaw('state, count(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state');

        $awaitingStates = ['SENT', 'PROOFING', 'INVOICED'];
        $inProductionStates = ['CONFIRMED', 'PROCURING', 'READY'];
        $sum = static fn (array $states): int => array_sum(
            array_map(static fn (string $s): int => (int) ($counts[$s] ?? 0), $states),
        );

        $awaitingOrders = (clone $base)
            ->whereIn('state', $awaitingStates)
            ->latest()
            ->limit(5)
            ->get(['id', 'reference', 'state']);

        return response()->json([
            'active' => (int) $counts->except(['CLOSED', 'CANCELLED'])->sum(),
            'awaiting' => $sum($awaitingStates),
            'in_production' => $sum($inProductionStates),
            'completed' => (int) ($counts['CLOSED'] ?? 0),
            'total' => (int) $counts->sum(),
            'awaiting_orders' => $awaitingOrders,
        ]);
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

    /**
     * Resolve by opaque reference (buyer/public URLs) or numeric id (staff and
     * internal callers), so /orders/{reference} works without leaking ids while
     * existing id-based callers keep working. Tenancy is enforced by the policy.
     */
    public function show(Request $request, string $ref): QuoteResource
    {
        $quote = Quote::query()
            ->where('reference', $ref)
            ->when(ctype_digit($ref), fn ($q) => $q->orWhere('id', (int) $ref))
            ->firstOrFail();

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
