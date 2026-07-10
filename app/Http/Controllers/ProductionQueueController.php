<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Http\Requests\AdvanceJobRequest;
use App\Http\Resources\ProductionJobResource;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Services\QueueService;
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
    public function __construct(private readonly QueueService $queue)
    {
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

        // Downloading the print-ready file IS the "started" signal - collapse the
        // separate advance click into the download. Idempotent: only fires from
        // READY, so a re-download at a later state is a no-op.
        if ($job->state === JobState::Ready) {
            $this->queue->advance($job, JobState::InProduction);
        }

        return $disk->download($ref, basename($ref));
    }
}
