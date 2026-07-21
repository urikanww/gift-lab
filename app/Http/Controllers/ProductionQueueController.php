<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Http\Requests\AdvanceJobRequest;
use App\Http\Resources\ProductionJobResource;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared production queue (spec 6.6): staff-only, FCFS by readiness, no
 * customer-type priority. State changes broadcast over staff.queue via Reverb.
 */
class ProductionQueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly \App\Services\ShipmentService $shipment,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        return ProductionJobResource::collection($this->queue->queue());
    }

    public function advance(AdvanceJobRequest $request, ProductionJob $job): ProductionJobResource
    {
        $target = JobState::from($request->string('state')->toString());
        $consignmentRef = $request->input('consignment_ref');
        $carrierInput = $request->input('carrier');
        $carrier = $carrierInput !== null ? \App\Enums\Carrier::from((string) $carrierInput) : null;
        $job = $this->queue->advance(
            $job,
            $target,
            $consignmentRef !== null ? (string) $consignmentRef : null,
            $carrier,
        );

        return new ProductionJobResource($job);
    }

    public function advanceNext(Request $request, ProductionJob $job): ProductionJobResource
    {
        $this->authorize('manageProduction', Quote::class);

        return new ProductionJobResource($this->queue->advanceNext($job));
    }

    public function advanceBatch(\App\Http\Requests\AdvanceBatchRequest $request): \Illuminate\Http\JsonResponse
    {
        $target = JobState::from($request->string('state')->toString());
        /** @var array<int, int> $ids */
        $ids = $request->input('job_ids');

        return response()->json($this->queue->advanceBatch($ids, $target));
    }

    /**
     * Stream a job's print-ready file (the 3D UV-flattened decal or the approved
     * proof artwork) off the PRIVATE artwork disk so the floor can print it.
     * Staff-gated by the same policy as the queue itself.
     *
     * The ref is written by our own pipeline, but it is still validated at the
     * boundary: only a well-formed key under the artwork/ prefix may stream, so
     * a malformed, foreign, or traversal value can never reach a disk read. A
     * missing file (e.g. pruned) yields 404, never a stack trace.
     */
    public function printFile(Request $request, ProductionJob $job): StreamedResponse
    {
        $this->authorize('manageProduction', Quote::class);

        $ref = $job->artwork_ref;

        if (! is_string($ref) || preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) !== 1) {
            abort(404);
        }

        $disk = Storage::disk((string) config('filesystems.artwork_disk'));

        if (! $disk->exists($ref)) {
            abort(404);
        }

        // Downloading no longer starts the job. It used to: the download WAS
        // the start signal, with nothing on screen saying so, which meant
        // opening a file to check it silently put the job into production.
        // Starting is now an explicit action - see advance().
        return $disk->download($ref, basename($ref));
    }

    /**
     * Hand a produced job to the courier and mark it SHIPPED with the returned
     * consignment ref + carrier. Staff-gated by the same policy as the rest of
     * the queue. A missing ship-to yields 422; a courier failure yields 502.
     */
    public function createShipment(Request $request, ProductionJob $job): JsonResponse
    {
        $this->authorize('manageProduction', Quote::class);

        // DomainRuleException (missing address / wrong state) is intentionally NOT
        // caught here - bootstrap/app.php renders it to a logged 422. Only the
        // courier failure needs a bespoke 502 with a safe, non-leaking message.
        try {
            $job = $this->shipment->createForJob($job);
        } catch (\App\Exceptions\CourierException $e) {
            \Illuminate\Support\Facades\Log::warning('Courier shipment failed.', ['job_id' => $job->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The courier could not create this shipment. Please try again.'], 502);
        }

        return response()->json([
            'data' => [
                'state' => $job->state->value,
                'carrier' => $job->carrier?->value,
                'consignment_ref' => $job->consignment_ref,
                'tracking_url' => $job->carrier?->trackingUrl((string) $job->consignment_ref),
            ],
        ]);
    }
}
