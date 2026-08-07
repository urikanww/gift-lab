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

## Task 1: N+1 regression guard on the users list

**Files:**
- Test: `tests/Feature/AdminUserManagementTest.php`

- [ ] **Step 1: Write the guard test**

Add to `tests/Feature/AdminUserManagementTest.php` (ensure `use Illuminate\Support\Facades\DB;` is present at the top — add it if not):

```php
it('lists users without an N+1 on the company relation', function (): void {
    Sanctum::actingAs($this->superadmin);

    // Several buyers across several companies. A naive company load would add
    // one query per row; the eager-load keeps the query count constant.
    Company::factory()->count(3)->create()->each(function ($c): void {
        User::factory()->count(3)->create(['company_id' => $c->id, 'role' => 'buyer']);
    });

    DB::enableQueryLog();
    $this->getJson('/api/admin/users?per_page=50')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Constant set: paginate count + users select + company eager-load (+ small slack).
    // Well below the ~9+ a per-row company load would produce for these rows.
    expect($queryCount)->toBeLessThanOrEqual(6);
});
```

- [ ] **Step 2: Run it — it should PASS immediately (the index already eager-loads `company`)**

Run: `vendor/bin/pest --filter="without an N+1 on the company relation"`
Expected: PASS. This test documents and locks the current good behavior.

- [ ] **Step 3: Prove the guard bites (temporary mutation, do NOT commit)**

Temporarily edit `AdminUserController@index`: remove `->with('company:id,name')`. Re-run the test.
Expected: FAIL (query count jumps above 6 because `serialize()` lazy-loads company per row).
Then RESTORE the `->with('company:id,name')` line. Re-run — PASS again. This confirms the guard actually detects an N+1. Do not commit the mutation.

- [ ] **Step 4: Commit the test**

```bash
git add tests/Feature/AdminUserManagementTest.php
git commit -m "test(admin): guard the users list against a company N+1"
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

- **Spec coverage (Piece D):** measured/evidence-based pass over the user-index query — the sort columns are the identified gap (Task 2); N+1 confirmed absent and locked (Task 1); dashboard verified already-indexed and N+1-free, so intentionally no change (documented in rationale). Search leading-wildcard limitation documented, not speculatively indexed. Covered.
- **Placeholders:** none — full test, full migration, exact commands with expected output.
- **Type/behavior consistency:** the migration indexes exactly the columns `AdminUserController@index` sorts by (`name`, `created_at`), matching the `sort` whitelist from Piece B. The N+1 guard asserts the eager-load (`->with('company:id,name')`) already in `index()`.
- **Honesty note:** these are low-risk changes justified by the shipped sortable UI, not a full production-data EXPLAIN pass (not possible in this session). If real-world profiling later shows filtered+sorted queries still filesort, the next step is a composite index (e.g. `(role, name)`) — deliberately deferred to avoid speculative indexing.
