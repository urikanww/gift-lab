<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| GET /api/quotes?q=<term>
|--------------------------------------------------------------------------
| Lands before the numeric id stops being displayed, so anyone holding an old
| "#1" from an email or invoice can still find that order. References below are
| set explicitly (and kept digit-free unless a test needs digits) so a LIKE on
| reference cannot accidentally satisfy an id assertion.
*/

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

it('finds a quote by a partial reference', function (): void {
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'ABC123XYZ']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'ZZZQQQWWW']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=C123')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'ABC123XYZ')
        ->assertJsonPath('data.0.id', $match->id);
});

it('finds a quote by its exact id', function (): void {
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q='.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('matches the id exactly, never as a substring', function (): void {
    // Ids are forced rather than left to autoincrement so the substring
    // relationship (1 inside 10) is deterministic, not lucky. A LIKE on the
    // integer key would match both - and would forfeit the primary key index.
    $match = Quote::factory()->create([
        'id' => 1, 'company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA',
    ]);
    Quote::factory()->create([
        'id' => 10, 'company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB',
    ]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q='.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 1);
});

it('accepts a leading # on an id', function (): void {
    // "#42" is how the id has been written everywhere until now, so buyers
    // paste it verbatim rather than stripping the hash themselves.
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=%23'.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('accepts a hash followed by a space', function (): void {
    // "# 42" is as plausible a paste as "#42"; both must reach the id branch
    // rather than falling through to a reference match.
    $match = Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q=%23%20'.$match->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('ignores an array search term instead of erroring', function (): void {
    // ?q[]=abc casts to string as a TypeError; the search box must not 500.
    Quote::factory()->count(2)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes?q[]=abc')->assertOk()->assertJsonCount(2, 'data');
});

it('treats a LIKE wildcard as a literal character', function (): void {
    // An unescaped % would match every row; escaped, it narrows to references
    // actually containing a percent sign - of which there are none.
    Quote::factory()->count(2)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q=%25')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns another company’s order when a buyer searches its reference', function (): void {
    // Mirror of the id guard below: both branches live in the same closure, so
    // this catches anyone later un-nesting only half the condition.
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'SECRETREF1']);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q='.$theirs->reference)->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never returns another company’s order when a buyer searches its id', function (): void {
    // THE security guard: the id match must not escape the company_id scope.
    // Flat (un-nested) the orWhere would, and a buyer could read any order by
    // guessing an id.
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'BBBBBBBBBB']);
    Sanctum::actingAs($this->buyer);

    $response = $this->getJson('/api/quotes?q='.$theirs->id)->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('lets staff search across every company', function (): void {
    $other = Company::factory()->create();
    $theirs = Quote::factory()->create(['company_id' => $other->id, 'reference' => 'FINDME1234']);
    Quote::factory()->create(['company_id' => $this->company->id, 'reference' => 'AAAAAAAAAA']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/quotes?q=FINDME')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $theirs->id);
});

it('returns the full list when no search term is given', function (): void {
    Quote::factory()->count(3)->create(['company_id' => $this->company->id]);
    Sanctum::actingAs($this->buyer);

    $this->getJson('/api/quotes')->assertOk()->assertJsonCount(3, 'data');
});
