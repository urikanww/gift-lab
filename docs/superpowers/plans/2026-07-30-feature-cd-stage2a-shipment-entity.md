# Stage 2a — Shipment Entity (relocate courier fields) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Introduce a `shipments` table (1:1 with production jobs for now) and move the six courier fields off `production_jobs` onto it, with **zero behavior change**.

**Architecture:** A `Shipment` groups jobs (`shipment_id` fk). In 2a each job gets its own shipment (1:1), so each job still books its own consignment — only the storage location of `consignment_ref/carrier/label_url/last_courier_status/last_courier_status_at/delivered_at` changes. Every reader/writer is repointed at `$job->shipment`. The webhook now resolves a **shipment** by `consignment_ref`. Because dropping the job columns breaks every reader simultaneously, 2a lands as one atomic refactor: the suite is green before and after, red in between.

**Tech Stack:** Laravel 11 / PHP 8.3 / Pest v3 (SQLite test DB, RefreshDatabase); the change is backend-only except frontend TS type field renames.

**Reference — exact touch points (file:line, from the investigation):**
- Columns to move: all on `production_jobs`; unique index on `consignment_ref` at `2026_07_28_000003_add_unique_index...`.
- Model: `app/Models/ProductionJob.php` $fillable :34-49, $casts :51-64 (`carrier`→Carrier :57, `last_courier_status_at`/`delivered_at`→datetime), `quote()` :69, `lineItems()` :77.
- Writers: `QueueService::advance` :251-259 (SHIPPED write), reship clear :572-577, markDelivered :464-465; `NinjaVanWebhookController` :172-178 (monotonic + delivered write); `ShipmentService::createForJob` passes into advance.
- Readers: `NinjaVanWebhookController::findJobForTrackingNumber` :240-260; `QueueService` inTransit :493-507 / needsAttention :519-531 / markDelivered guard :441 / resolveReturn* :522-577,:600,:627; `OrderTracker::shipments` :117-141 + itemsCompleted :100-105; `ProductionJobResource` :31-45; `QuoteResource` shipmentSummary :164-166; `OrderTrackingUpdated::broadcastWith` :53-73; `StaffNotifier` :157-158; `Mail/ParcelReturnedMail` :47-48; `Events/ParcelReturned` :60-61.
- Booking: `NinjaVanTrackingNumber::forJob` :52; `ShipmentService::createForJob` :30-97; milestone dedup `QueueService` :313-336.
- Tests referencing courier fields: NinjaVanWebhookTest, ProductionQueueTest, CreateShipmentTest, ReturnResolutionTest, ManualDeliveryTest, ParcelReturnTest, NeedsAttentionSurfaceTest, OrderShipmentVisibilityTest, OrderTrackerTest, CosmeticLowsTest, StateMachineTest, CourierFixtureTest (+ NinjaVanClientTest/CourierConfigTest likely unaffected — they test the client/config, not the job row).

---

### Task 1: DB + models (shipments table, backfill, drop job columns, relations, factory)

**Files:**
- Create: `database/migrations/2026_07_31_000001_create_shipments_and_relocate_courier_fields.php`
- Create: `app/Models/Shipment.php`
- Create: `database/factories/ShipmentFactory.php`
- Modify: `app/Models/ProductionJob.php` (remove 6 courier fields from $fillable/$casts, add `shipment_id` + `shipment()` relation)
- Modify: `app/Models/Quote.php` (add `shipments()` relation)
- Test: `tests/Feature/ShipmentRelocationTest.php`

- [ ] **Step 1: Write the failing migration/backfill test**

```php
<?php
declare(strict_types=1);

use App\Enums\JobState;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\Shipment;

it('backfills one shipment per job with courier data and relocates the fields', function (): void {
    // Build a shipped job the pre-migration way is impossible post-migration; instead
    // assert the schema + relation shape after migrate:fresh (RefreshDatabase already ran).
    $quote = Quote::factory()->create();
    $job = ProductionJob::factory()->for($quote)->create(['state' => JobState::Shipped->value]);
    $shipment = Shipment::factory()->for($quote)->create(['consignment_ref' => 'NVSGREL0001']);
    $job->shipment()->associate($shipment)->save();

    expect($job->fresh()->shipment->consignment_ref)->toBe('NVSGREL0001')
        ->and($shipment->fresh()->jobs->pluck('id'))->toContain($job->id)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('production_jobs', 'consignment_ref'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('production_jobs', 'shipment_id'))->toBeTrue();
});
```

Run: `php artisan test --filter=ShipmentRelocation` → FAIL (no Shipment model/table).

- [ ] **Step 2: Migration**

`up()`: create `shipments`; add `shipment_id` to `production_jobs`; backfill; drop the 6 columns + unique index.

```php
public function up(): void
{
    Schema::create('shipments', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
        $table->string('consignment_ref', 128)->nullable()->unique();
        $table->string('carrier', 32)->nullable();
        $table->string('label_url', 2048)->nullable();
        $table->string('last_courier_status', 255)->nullable();
        $table->timestamp('last_courier_status_at')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->timestamps();
    });

    Schema::table('production_jobs', function (Blueprint $table): void {
        $table->foreignId('shipment_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
    });

    // Backfill: one shipment per EXISTING job (uniform, so 2b grouping is simple),
    // copying its courier fields. Chunk to stay memory-safe on large tables.
    DB::table('production_jobs')->orderBy('id')->chunkById(500, function ($jobs): void {
        foreach ($jobs as $job) {
            $shipmentId = DB::table('shipments')->insertGetId([
                'quote_id' => $job->quote_id,
                'consignment_ref' => $job->consignment_ref,
                'carrier' => $job->carrier,
                'label_url' => $job->label_url,
                'last_courier_status' => $job->last_courier_status,
                'last_courier_status_at' => $job->last_courier_status_at,
                'delivered_at' => $job->delivered_at,
                'created_at' => $job->created_at,
                'updated_at' => $job->updated_at,
            ]);
            DB::table('production_jobs')->where('id', $job->id)->update(['shipment_id' => $shipmentId]);
        }
    });

    Schema::table('production_jobs', function (Blueprint $table): void {
        $table->dropUnique(['consignment_ref']);
        $table->dropColumn(['consignment_ref', 'carrier', 'label_url', 'last_courier_status', 'last_courier_status_at', 'delivered_at']);
    });
}
```

`down()`: re-add the 6 columns + unique index on `production_jobs`, copy fields back from each job's shipment, drop `shipment_id`, drop `shipments`.

```php
public function down(): void
{
    Schema::table('production_jobs', function (Blueprint $table): void {
        $table->string('consignment_ref', 128)->nullable()->after('artwork_refs');
        $table->string('carrier', 32)->nullable()->after('consignment_ref');
        $table->string('label_url', 2048)->nullable()->after('carrier');
        $table->string('last_courier_status', 255)->nullable()->after('label_url');
        $table->timestamp('last_courier_status_at')->nullable()->after('last_courier_status');
        $table->timestamp('delivered_at')->nullable()->after('last_courier_status_at');
    });

    DB::table('production_jobs')->whereNotNull('shipment_id')->orderBy('id')->chunkById(500, function ($jobs): void {
        foreach ($jobs as $job) {
            $s = DB::table('shipments')->where('id', $job->shipment_id)->first();
            if ($s) {
                DB::table('production_jobs')->where('id', $job->id)->update([
                    'consignment_ref' => $s->consignment_ref,
                    'carrier' => $s->carrier,
                    'label_url' => $s->label_url,
                    'last_courier_status' => $s->last_courier_status,
                    'last_courier_status_at' => $s->last_courier_status_at,
                    'delivered_at' => $s->delivered_at,
                ]);
            }
        }
    });

    Schema::table('production_jobs', function (Blueprint $table): void {
        $table->unique('consignment_ref');
        $table->dropConstrainedForeignId('shipment_id');
    });
    Schema::dropIfExists('shipments');
}
```

- [ ] **Step 3: Shipment model + factory**

`app/Models/Shipment.php`:
```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\Carrier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id', 'consignment_ref', 'carrier', 'label_url',
        'last_courier_status', 'last_courier_status_at', 'delivered_at',
    ];

    protected $casts = [
        'carrier' => Carrier::class,
        'last_courier_status_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<ProductionJob> */
    public function jobs(): HasMany
    {
        return $this->hasMany(ProductionJob::class);
    }
}
```

`database/factories/ShipmentFactory.php`: standard factory, `quote_id => Quote::factory()`, other fields null by default; add states if convenient (e.g. `shipped()` setting a consignment_ref). Match the repo's existing factory style.

- [ ] **Step 4: ProductionJob + Quote relations**

`ProductionJob.php`: remove the 6 courier keys from `$fillable` and their `$casts` entries; add `'shipment_id'` to `$fillable`; add:
```php
public function shipment(): BelongsTo
{
    return $this->belongsTo(Shipment::class);
}
```
`Quote.php`: add
```php
/** @return HasMany<Shipment> */
public function shipments(): HasMany
{
    return $this->hasMany(Shipment::class);
}
```

- [ ] **Step 5: Run the relocation test** → PASS. (The rest of the suite is now RED — that's expected; Tasks 2-5 repoint the readers/writers. Do NOT commit yet.)

---

### Task 2: Booking + service writes (forShipment, ShipmentService, QueueService writes)

**Files:** `app/Services/Courier/NinjaVanTrackingNumber.php`, `app/Services/ShipmentService.php`, `app/Services/QueueService.php`, `app/Services/QueueService.php` (buildJobsForQuote).

- [ ] **Step 1: `NinjaVanTrackingNumber::forShipment`**

Add `public static function forShipment(int $quoteId, int $shipmentId): string` — same derivation as `forJob` but keyed on `$shipmentId` (body = `base_convert($shipmentId,10,36)`, overflow hash `md5($quoteId.':'.$shipmentId)`). Keep `forJob` only if still referenced elsewhere; otherwise remove it and its now-dead `forQuote` sibling if unused (grep first).

- [ ] **Step 2: `buildJobsForQuote` creates a 1:1 shipment per job**

In `QueueService::buildJobsForQuote`, inside the `DB::transaction` job-creation loop, after `ProductionJob::create([...])` (which no longer takes courier fields — it never did), create the job's shipment and associate:
```php
$shipment = \App\Models\Shipment::create(['quote_id' => $quote->id]);
$job->shipment()->associate($shipment)->save();
```
(1:1 in 2a; Phase 2b changes this to one shipment for the whole quote.)

- [ ] **Step 3: `ShipmentService::createForJob` writes the job's shipment**

The job now carries a `shipment` (created at build). Rework `createForJob`:
- Guard idempotency on `$job->shipment?->consignment_ref !== null` (was `$job->consignment_ref`).
- `$trackingNumber = NinjaVanTrackingNumber::forShipment((int) $quote->id, (int) $job->shipment->id);`
- After the courier call, in the transaction: write `consignment_ref/carrier/label_url` onto **`$job->shipment`** (save it), then `$this->queue->advance($job, JobState::Shipped)` (advance no longer needs the consignment args — see Task 3; it reads them off the shipment). Keep the same return (the job).
- `CourierShipment` `reference:` stays `(string) $quote->reference`.

> If a job somehow has no shipment (legacy), create one first (defensive): `$job->shipment ?? tap(Shipment::create(['quote_id'=>$quote->id]), fn($s)=>$job->shipment()->associate($s)->save())`.

- [ ] **Step 4: `QueueService::advance` writes courier fields on the shipment**

`advance(ProductionJob $job, JobState $target, ?string $consignmentRef=null, ?Carrier $carrier=null, ?string $labelUrl=null)`: when `$target===Shipped` and `$consignmentRef!==null`, write onto `$job->shipment` instead of `$job` (:251-259). BUT since ShipmentService now writes the shipment before calling advance, prefer: keep advance's signature, and if a `$consignmentRef` is passed, set it on the job's shipment (`$job->shipment->consignment_ref = ...; ...->save()`). The manual `/advance` endpoint (`AdvanceJobRequest`, consignment on SHIPPED) also flows here — it must land on the shipment. Audit payload (:267) reads `$job->shipment?->consignment_ref`. Milestone context (:327-332) reads shipment fields.
- Reship clear (:572-577) clears the **shipment's** fields (`$job->shipment->consignment_ref = null; carrier=null; label_url=null; last_courier_status=null; last_courier_status_at=null; delivered_at=null; $job->shipment->save();`).
- markDelivered (:464-465) writes `MANUAL_DELIVERED_STATUS` + `last_courier_status_at` onto `$job->shipment`.

Do NOT run tests to green yet — readers in Task 4/5 still reference removed fields.

---

### Task 3: Webhook repoint (resolve a shipment by consignment_ref)

**File:** `app/Http/Controllers/NinjaVanWebhookController.php`

- [ ] **Step 1:** `findJobForTrackingNumber` → `findShipmentForTrackingNumber(string): ?Shipment`. Exact match on `Shipment::where('consignment_ref', $tn)`; fallback suffix-scan bounded to shipments that have **at least one SHIPPED job** (`whereHas('jobs', fn($q)=>$q->where('state', Shipped))`) and non-empty consignment_ref; keep the unambiguous-single-match rule (M14).
- [ ] **Step 2:** In `handle`, resolve a `$shipment`; if null → ack 200 (unknown), unchanged. Lock the **shipment** row (or its jobs) for the transaction. The monotonic status guard + `last_courier_status/_at` write now target `$shipment`. On `deliver`: set `$shipment->delivered_at`, save shipment, then advance **each member job that is still SHIPPED** to CLOSED (loop `$shipment->jobs()->where('state', Shipped)->get()` → `$queue->advance($lockedJob, Closed)`), preserving the per-job TOCTOU lock. For an intermediate/needsAttention status, save the shipment and broadcast the tracker for the quote once. Staff `parcelReturned` alert: pass a representative job or adapt `StaffNotifier`/`ParcelReturned` to take the shipment (see Task 5). Event idempotency (sha256 body) unchanged.

> In 2a a shipment has exactly one job (1:1), so "each member job" is a loop of one — behavior identical. Writing it as a loop makes 2b a no-op here.

---

### Task 4: Query surfaces (inTransit / needsAttention / tracker / itemsCompleted)

**Files:** `app/Services/QueueService.php`, `app/Services/OrderTracker.php`.

- [ ] **Step 1: QueueService::inTransit / needsAttention** — these currently filter jobs by `last_courier_status`. Repoint to filter on the job's shipment: `whereHas('shipment', fn($q)=>$q->whereNull('last_courier_status')->orWhereNotIn('last_courier_status', NinjaVanStatusMapper::NEEDS_ATTENTION_LABELS))` for inTransit, and the `whereIn` for needsAttention; keep `state = SHIPPED` + `whereHas('quote')`; eager-load `['quote','shipment','lineItems.product']`; needsAttention orderBy the shipment's `last_courier_status_at` (use a join or `orderByDesc` on a subquery, or order in PHP after get()). markDelivered guard (:441) reads `$job->shipment?->last_courier_status`.
- [ ] **Step 2: OrderTracker::shipments()** — read real `$quote->shipments` rows (state derived: include shipments that have a job in Shipped/Closed and a non-null consignment_ref). Map to the same payload keys (`carrier_label, tracking_url, ref, status, status_at, delivered_at`). `itemsCompleted` (:100-105) — the SHIPPED/CLOSED-and-not-needs-attention filter now reads the shipment's `last_courier_status`.

---

### Task 5: Resources, broadcast, notifiers, frontend types

**Files:** `app/Http/Resources/ProductionJobResource.php`, `app/Http/Resources/QuoteResource.php`, `app/Events/OrderTrackingUpdated.php` (reads via OrderTracker, likely unchanged), `app/Services/StaffNotifier.php`, `app/Mail/ParcelReturnedMail.php`, `app/Events/ParcelReturned.php`, `frontend/src/types.ts`.

- [ ] **Step 1: ProductionJobResource** — `consignment_ref/carrier/carrier_label/tracking_url/last_courier_status/last_courier_status_at` now read `$this->shipment?->...`. `needs_attention` = `NinjaVanStatusMapper::isNeedsAttentionLabel($this->shipment?->last_courier_status)`. Eager-load `shipment` wherever the resource is collected (queue()/inTransit()/needsAttention() already updated to load it).
- [ ] **Step 2: QuoteResource shipmentSummary** (:164-166) — read from `$quote->shipments` (or `$job->shipment`) instead of the job columns. Keep the same output keys/shape (`OrderShipment` TS type).
- [ ] **Step 3: StaffNotifier :157-158 / ParcelReturnedMail :47-48 / ParcelReturned :60-61** — these read `$job->consignment_ref`/`last_courier_status`. Repoint to `$job->shipment?->...`. Minimal change; keep signatures.
- [ ] **Step 4: frontend/src/types.ts** — the `ProductionJob` interface still exposes `consignment_ref/carrier/carrier_label/tracking_url/last_courier_status/last_courier_status_at` (the resource still returns those keys, just sourced from the shipment) — **no TS change needed** unless a key name changed (it doesn't). Confirm `OrderShipment` and `Shipment` types still match. If the resource keys are unchanged, this step is a no-op verification.

- [ ] **Step 5: Repoint the tests**

Update every failing test to build/assert courier data on a **shipment**. Common pattern: where a test did `ProductionJob::factory()->create(['consignment_ref'=>..., 'last_courier_status'=>...])`, change to create the job, create/attach a `Shipment::factory()->create([...])`, and assert on `$job->shipment` (or the shipment row). Files to sweep (from the map): NinjaVanWebhookTest, ProductionQueueTest, CreateShipmentTest, ReturnResolutionTest, ManualDeliveryTest, ParcelReturnTest, NeedsAttentionSurfaceTest, OrderShipmentVisibilityTest, OrderTrackerTest, CosmeticLowsTest, StateMachineTest, CourierFixtureTest. Also check any test helpers (e.g. `ninjaVanShippedJob`) — update them once and many tests follow. The Stage-1 courier-independence guard test: the webhook now resolves a **shipment** by consignment_ref; the guard (order ref / job id must not resolve) still holds — update it to attach the consignment to a shipment.

- [ ] **Step 6: Full suite green + commit**

Run: `php artisan test` → all green. Then frontend: `cd /d/work/NexGen/gift-lab/frontend && npx vitest run && npx tsc --noEmit` → green (should be unaffected unless a resource key changed).

```bash
git add -A
git commit -m "refactor(courier): relocate courier fields onto a shipments entity (1:1, behavior-preserving)"
```

> Single commit for the whole relocation (the suite can't be green mid-move). The TDD anchor was the ShipmentRelocationTest + the pre-existing courier suite acting as the behavior-preservation harness.

---

## Self-Review

- **Behavior preserved:** 1:1 shipment per job; every field read/write repointed; webhook resolves the shipment (loop-of-one closes its job); tracker/resources emit identical keys. The full pre-existing courier suite is the regression harness — if it stays green, behavior is preserved.
- **Green boundary:** 2a is one commit (a field relocation can't be green mid-way). Anchored by ShipmentRelocationTest + the existing suite.
- **2b-ready:** webhook closes "each SHIPPED member job" (loop), buildJobsForQuote creates the shipment as a seam to later collapse to one-per-quote, tracker reads real shipment rows.
- **Invariants:** unique `consignment_ref` now on `shipments`; `forShipment` keyed on shipment id; fail-closed webhook + TOCTOU + idempotency preserved.
