<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\JobState;
use App\Http\Requests\AdvanceJobRequest;
use App\Http\Resources\ProductionJobResource;
use App\Models\ProductionJob;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        abort_unless($request->user()->isStaff(), 403);

        return ProductionJobResource::collection($this->queue->queue());
    }

    public function advance(AdvanceJobRequest $request, ProductionJob $job): ProductionJobResource
    {
        $target = JobState::from($request->string('state')->toString());
        $job = $this->queue->advance($job, $target);

        return new ProductionJobResource($job);
    }
}
