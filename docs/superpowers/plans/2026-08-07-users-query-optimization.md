# Users — Query Optimization (Piece D) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock the users-list query against N+1 regressions and index the columns the new sortable table orders by, so the list stays fast as the users table grows.

**Architecture:** Two small, evidence-based changes: a query-count regression test around `GET /api/admin/users`, and a migration adding indexes on `users.name` and `users.created_at` (the list's sort columns). No dashboard work — its metric queries are already single grouped queries on indexed columns and the activity feed is N+1-free.

**Tech Stack:** Laravel 12 + Pest.

Spec: `docs/superpowers/specs/2026-08-07-admin-dashboard-users-ux-design.md` (Piece D).

## Scope rationale (read before implementing)

Verified against the code:
- `users` indexes today: `role`, `company_id`, `email` (unique). **No index on `name` or `created_at`** — the exact columns the new list sorts by (default `name_asc`, plus `created_asc/desc`). On a large table this is a filesort.
- `companies.name` is already indexed; `quotes.state`, `production_jobs.state` are indexed. The dashboard `pipeline()`/`production()` are single `groupBy('state')` count queries; the activity feed is documented N+1-free (`quoteReferences` batch). **No dashboard change is warranted.**
- The broadened search (`LOWER(name/email/company.name) LIKE '%q%'`) uses a **leading wildcard**, which no B-tree index can serve. This is a known limitation, NOT something to "fix" with an index. If search latency ever becomes a problem, the future option is a full-text index (MySQL FULLTEXT) or a search service — out of scope here; do not add speculative indexes for it.

A full EXPLAIN-based measurement wants a production-representative dataset (this repo's tests run on sqlite; prod is MySQL). Rather than speculatively index broadly, this plan makes only the two low-risk, directly-justified changes above and leaves a documented note for a later data-driven pass if needed.

## File Structure

- Test: `tests/Feature/AdminUserManagementTest.php` — add an N+1 guard.
- Create: `database/migrations/2026_08_07_000001_add_sort_indexes_to_users.php` — index `name` + `created_at`.

---

## Task 1: Guard that the users list carries company data

**Files:**
- Test: `tests/Feature/AdminUserManagementTest.php`

**Why a content guard, not a query-count guard:** `serialize()` reads company only when `relationLoaded('company')` is true. So dropping `->with('company:id,name')` does NOT cause an N+1 — it silently returns `company => null`. A query-count assertion would stay green through that real regression. The meaningful guard asserts the observable contract: the list payload actually carries company data. That assertion fails the moment the eager-load is dropped (company goes null).

- [ ] **Step 1: Write the guard test**

Add to `tests/Feature/AdminUserManagementTest.php`:

```php
it('includes each buyer company in the list payload', function (): void {
    Sanctum::actingAs($this->superadmin);

    $acme = Company::factory()->create(['name' => 'Contract Guard Co']);
    $buyer = User::factory()->create(['company_id' => $acme->id, 'role' => 'buyer']);

    $response = $this->getJson('/api/admin/users?per_page=50')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $buyer->id);

    expect($row)->not->toBeNull()
        ->and($row['company'])->not->toBeNull()
        ->and($row['company']['name'])->toBe('Contract Guard Co');
});
```

- [ ] **Step 2: Run it — expect PASS**

Run: `vendor/bin/pest --filter="includes each buyer company"`
Expected: PASS (the index eager-loads company; `serialize()` emits it).

- [ ] **Step 3: Prove the guard bites (temporary mutation, do NOT commit)**

Temporarily remove `->with('company:id,name')` from `AdminUserController@index`, re-run the test.
Expected: FAIL — `company` is now `null`, so `->and($row['company'])->not->toBeNull()` fails.
Then RESTORE the line; confirm `git diff app/Http/Controllers/AdminUserController.php` is empty; re-run — PASS.

- [ ] **Step 4: Commit the test (controller unchanged)**

```bash
git add tests/Feature/AdminUserManagementTest.php
git commit -m "test(admin): guard that the users list carries company data"
```

---

## Task 2: Index the users sort columns

**Files:**
- Create: `database/migrations/2026_08_07_000001_add_sort_indexes_to_users.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_07_000001_add_sort_indexes_to_users.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin users list (AdminUserController@index) sorts by name (default) and
 * by created_at. Neither column was indexed, so ORDER BY on a large users table
 * falls back to a filesort. These single-column indexes back the sort.
 *
 * Not indexed here: the free-text search is a leading-wildcard LIKE on
 * name/email/company.name, which a B-tree index cannot serve - a full-text
 * index is the right tool if/when search latency is measured to matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['name']);
            $table->dropIndex(['created_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration against a fresh test/dev DB**

Run: `php artisan migrate --env=e2e` (uses the isolated e2e sqlite from the Layer-1 setup).
Expected: the new migration runs without error (`Migrating: 2026_08_07_000001_add_sort_indexes_to_users` → `DONE`).

- [ ] **Step 3: Verify rollback works (down)**

Run: `php artisan migrate:rollback --env=e2e --step=1`
Expected: `Rolling back: 2026_08_07_000001_add_sort_indexes_to_users` → `DONE` with no error (confirms `dropIndex` is correct).
Then re-apply: `php artisan migrate --env=e2e`.

- [ ] **Step 4: Run the full suite (migrations run fresh per test run)**

Run: `vendor/bin/pest tests/Feature/AdminUserManagementTest.php`
Expected: PASS — the migration applies cleanly in the test bootstrap and the list/sort behavior is unchanged (correctness already covered by the sort/filter tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_07_000001_add_sort_indexes_to_users.php
git commit -m "perf(users): index name and created_at for list sorting"
```

---

## Self-Review

- **Spec coverage (Piece D):** measured/evidence-based pass over the user-index query — the sort columns are the identified gap (Task 2); the company-in-payload contract is locked, and an N+1 is structurally impossible here (`serialize()` only reads an eager-loaded relation) so the guard targets the real regression instead (Task 1); dashboard verified already-indexed and N+1-free, so intentionally no change (documented in rationale). Search leading-wildcard limitation documented, not speculatively indexed. Covered.
- **Placeholders:** none — full test, full migration, exact commands with expected output.
- **Type/behavior consistency:** the migration indexes exactly the columns `AdminUserController@index` sorts by (`name`, `created_at`), matching the `sort` whitelist from Piece B. The N+1 guard asserts the eager-load (`->with('company:id,name')`) already in `index()`.
- **Honesty note:** these are low-risk changes justified by the shipped sortable UI, not a full production-data EXPLAIN pass (not possible in this session). If real-world profiling later shows filtered+sorted queries still filesort, the next step is a composite index (e.g. `(role, name)`) — deliberately deferred to avoid speculative indexing.
