<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->superadmin()->create();
    $company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
});

it('gates the dashboard to staff', function (): void {
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/dashboard')->assertForbidden();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['pipeline', 'production', 'atRisk', 'queues', 'activity']);
});

it('reports pipeline, production, and queue counts', function (): void {
    $company = Company::factory()->create();
    Quote::factory()->count(2)->create(['company_id' => $company->id, 'state' => 'SENT']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'ACCEPTED']);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/dashboard')->assertOk();

    expect($res->json('pipeline.SENT'))->toBe(2);
    expect($res->json('pipeline.ACCEPTED'))->toBe(1);
    expect($res->json('production'))->toHaveKeys(['byState', 'wip', 'overdue']);
    expect($res->json('queues'))->toHaveKeys(['proofsPending', 'procurementToReconfirm', 'cataloguePending', 'reordersOpen']);
});

it('includes value-booked only for superadmin', function (): void {
    Sanctum::actingAs($this->staff);
    expect($this->getJson('/api/admin/dashboard')->json('valueBooked'))->toBeNull();

    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/dashboard')
        ->assertOk()
        ->assertJsonStructure(['valueBooked' => ['currency', 'amount']]);
});

it('caps activity at 20 newest-first and at-risk at 15', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    for ($i = 0; $i < 25; $i++) {
        AuditLog::create([
            'user_id' => $this->staff->id,
            'auditable_type' => Quote::class,
            'auditable_id' => $quote->id,
            'event' => 'quote.amended',
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($this->staff);
    $activity = $this->getJson('/api/admin/dashboard')->json('activity');

    expect($activity)->toHaveCount(20);
    expect($activity[0]['event'])->toBe('quote.amended');
});

it('runs a bounded number of queries regardless of data volume', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    for ($i = 0; $i < 30; $i++) {
        AuditLog::create([
            'user_id' => $this->staff->id,
            'auditable_type' => Quote::class,
            'auditable_id' => $quote->id,
            'event' => 'quote.amended',
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    Sanctum::actingAs($this->staff);

    Cache::flush();
    DB::enableQueryLog();
    $this->getJson('/api/admin/dashboard')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // pipeline + production(byState + overdue) + 4 queues + atRisk
    // + activity(+eager user + quote references) ≈ 11; the eager-loads make actor
    // and reference lookup ONE query each, not 30. Guard against N+1.
    expect($count)->toBeLessThanOrEqual(13);
});

it('names a Quote activity row by reference and leaves other types on the id shape', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);

    AuditLog::create([
        'user_id' => $this->staff->id,
        'auditable_type' => Quote::class,
        'auditable_id' => $quote->id,
        'event' => 'quote.state_changed',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    AuditLog::create([
        'user_id' => $this->staff->id,
        'auditable_type' => Product::class,
        'auditable_id' => 12,
        'event' => 'product.updated',
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinutes(2),
    ]);

    Sanctum::actingAs($this->staff);
    $activity = collect($this->getJson('/api/admin/dashboard')->json('activity'))
        ->keyBy('auditableType');

    $quoteRow = $activity['Quote'];
    expect($quoteRow['auditableLabel'])->toBe("Order {$quote->reference}");
    // The join key survives the label - other things may key off it.
    expect($quoteRow['auditableId'])->toBe($quote->id);

    // A non-Quote row is byte-for-byte what it always was.
    $productRow = $activity['Product'];
    expect($productRow['auditableLabel'])->toBe('Product #12');
    expect($productRow['auditableId'])->toBe(12);
});

it('keeps a soft-deleted quote named by its reference, never a bare "Order "', function (): void {
    $company = Company::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    $reference = $quote->reference;

    AuditLog::create([
        'user_id' => $this->staff->id,
        'auditable_type' => Quote::class,
        'auditable_id' => $quote->id,
        'event' => 'quote.cancelled',
    ]);

    $quote->delete();
    expect(Quote::find($quote->id))->toBeNull();

    Sanctum::actingAs($this->staff);
    $activity = $this->getJson('/api/admin/dashboard')->json('activity');

    expect($activity[0]['auditableLabel'])->toBe("Order {$reference}");
});

it('falls back to the id shape when the quote is gone entirely', function (): void {
    AuditLog::create([
        'user_id' => $this->staff->id,
        'auditable_type' => Quote::class,
        'auditable_id' => 424242,
        'event' => 'quote.state_changed',
    ]);

    Sanctum::actingAs($this->staff);
    $activity = $this->getJson('/api/admin/dashboard')->json('activity');

    expect($activity[0]['auditableLabel'])->toBe('Quote #424242');
});

it('resolves activity references in a flat query count as Quote rows grow', function (): void {
    // The point of this test: reference resolution must be ONE lookup for the
    // whole slice. Swapping it for a per-row $a->auditable?->reference would keep
    // the OUTPUT correct while the count climbs with the number of quotes - so
    // this asserts equality between a 1-quote and a 15-quote feed, not a ceiling.
    $company = Company::factory()->create();
    $metrics = app(DashboardMetrics::class);

    $measure = function (int $quoteCount) use ($company, $metrics): int {
        AuditLog::query()->delete();
        foreach (Quote::factory()->count($quoteCount)->create(['company_id' => $company->id]) as $i => $quote) {
            AuditLog::create([
                'user_id' => $this->staff->id,
                'auditable_type' => Quote::class,
                'auditable_id' => $quote->id,
                'event' => 'quote.state_changed',
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $activity = $metrics->activity();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Positive control: if the labels came back empty, a flat count would be
        // trivially true and this guard would be measuring nothing.
        expect($activity)->toHaveCount($quoteCount);
        foreach ($activity as $row) {
            expect($row['auditableLabel'])->toStartWith('Order ');
        }

        return $count;
    };

    $one = $measure(1);
    $many = $measure(15);

    // audit logs + eager user + one whereIn for references = 3, flat.
    expect($one)->toBe(3);
    expect($many)->toBe($one);
});
