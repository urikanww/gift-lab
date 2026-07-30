<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Exceptions\CourierException;
use App\Http\Requests\AdvanceBatchRequest;
use App\Http\Requests\AdvanceJobRequest;
use App\Http\Requests\MarkDeliveredRequest;
use App\Http\Requests\ResolveReturnRequest;
use App\Http\Resources\ProductionJobResource;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Services\QueueService;
use App\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
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
        private readonly ShipmentService $shipment,
    ) {}

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
        $carrier = $carrierInput !== null ? Carrier::from((string) $carrierInput) : null;
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

    public function advanceBatch(AdvanceBatchRequest $request): JsonResponse
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

        // A job now carries one file per artwork line (artwork_refs); the caller
        // names which by ?ref. Only a ref actually on this job may stream, so a
        // foreign/guessed key can never reach a disk read.
        $ref = (string) $request->query('ref', '');
        $onJob = collect($job->artwork_refs ?? [])->pluck('ref')->contains($ref);

        if (! $onJob || preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) !== 1) {
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
     * Stream a ZIP of every print-ready file on a job, one entry per artwork
     * line, each named "{product_name}-{basename(ref)}" so the floor can tell
     * the plates apart. Same staff gate and same artwork/ boundary validation as
     * the single-file download; refs that fail the guard or are absent from the
     * disk are skipped, and a job with nothing printable yields 404.
     *
     * Files are pulled through the disk (addFromString) rather than by local
     * path, so this works unchanged on the local dev disk and on remote Spaces.
     */
    public function printFileZip(Request $request, ProductionJob $job): StreamedResponse
    {
        $this->authorize('manageProduction', Quote::class);

        $disk = Storage::disk((string) config('filesystems.artwork_disk'));

        /** @var array<string, string> $entries zip-entry name => file contents */
        $entries = [];
        foreach (($job->artwork_refs ?? []) as $item) {
            $ref = is_array($item) ? (string) ($item['ref'] ?? '') : '';
            if (preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) !== 1 || ! $disk->exists($ref)) {
                continue;
            }

            $label = is_array($item) ? (string) ($item['product_name'] ?? '') : '';
            $base = ($label !== '' ? $label.'-' : '').basename($ref);
            $name = $base;
            $n = 2;
            while (isset($entries[$name])) {
                $name = pathinfo($base, PATHINFO_FILENAME).'-'.$n.'.'.pathinfo($base, PATHINFO_EXTENSION);
                $n++;
            }
            $entries[$name] = (string) $disk->get($ref);
        }

        if ($entries === []) {
            abort(404);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'printfiles').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not build the print-file archive.');
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return response()->streamDownload(function () use ($zipPath): void {
            readfile($zipPath);
            @unlink($zipPath);
        }, "job-{$job->id}-print-files.zip", [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
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
        } catch (CourierException $e) {
            Log::warning('Courier shipment failed.', ['job_id' => $job->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The courier could not create this shipment. Please try again.'], 502);
        }

        $shipment = $job->shipment;

        return response()->json([
            'data' => [
                'state' => $job->state->value,
                'carrier' => $shipment?->carrier?->value,
                'consignment_ref' => $shipment?->consignment_ref,
                'tracking_url' => $shipment?->carrier?->trackingUrl((string) $shipment?->consignment_ref),
                'label_url' => $shipment?->label_url,
            ],
        ]);
    }

    /**
     * Pull one item out of an order's shipment so it ships as a separate parcel
     * (its own consignment). Only allowed while the shipment is unbooked and
     * groups more than one job - see QueueService::splitJobToOwnShipment, which
     * throws a DomainRuleException (rendered 422) otherwise.
     */
    public function split(Request $request, ProductionJob $job): ProductionJobResource
    {
        $this->authorize('manageProduction', Quote::class);

        $this->queue->splitJobToOwnShipment($job);

        return new ProductionJobResource($job->fresh()->load('shipment'));
    }

    /**
     * Staff resolve a job NinjaVan reported returned/failed: close (write
     * off), reship (re-queue for a fresh shipment), or cancel_credit (cancel
     * the order, void any live invoice, mint a credit note). Refused with a
     * 422 (DomainRuleException) when the job isn't actually flagged
     * returned/failed - see QueueService::resolveReturn.
     */
    public function resolveReturn(ResolveReturnRequest $request, ProductionJob $job): ProductionJobResource
    {
        $this->authorize('manageProduction', Quote::class);

        $job = $this->queue->resolveReturn(
            $job,
            $request->string('disposition')->toString(),
            $request->input('note'),
        );

        return new ProductionJobResource($job);
    }

    /**
     * Jobs in transit (SHIPPED, not yet delivered) - the "awaiting delivery"
     * list staff use to confirm delivery by hand when the courier webhook is
     * silent. Read-only; separate from the FCFS production board (index()).
     */
    public function inTransit(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        return ProductionJobResource::collection($this->queue->inTransit());
    }

    /**
     * Returned/failed parcels awaiting staff resolution (reship/close/
     * cancel-credit). Read-only; separate from the in-transit board, which now
     * excludes these. Staff-gated like the rest of the queue.
     */
    public function needsAttention(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        return ProductionJobResource::collection($this->queue->needsAttention());
    }

    /**
     * Staff manually confirm a shipped parcel was delivered - the fallback for
     * when NinjaVan's delivery webhook never arrives. Closes the job (and the
     * quote when it's the last one), firing the buyer's delivered milestone,
     * exactly as the webhook would. Refused with a 422 (DomainRuleException)
     * when the job isn't SHIPPED, or is flagged returned/failed (that routes to
     * resolveReturn instead) - see QueueService::markDelivered.
     */
    public function markDelivered(MarkDeliveredRequest $request, ProductionJob $job): ProductionJobResource
    {
        $this->authorize('manageProduction', Quote::class);

        $job = $this->queue->markDelivered($job, $request->input('note'));

        return new ProductionJobResource($job);
    }
}
