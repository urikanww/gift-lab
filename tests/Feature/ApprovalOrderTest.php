<?php

declare(strict_types=1);

use App\Enums\ApprovalOrder;
use App\Enums\QuoteState;
use App\Exceptions\DomainRuleException;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Auth;

it('defaults a new quote to price_first', function (): void {
    $quote = Quote::factory()->create();

    expect($quote->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('persists proof_first via the factory state', function (): void {
    $quote = Quote::factory()->proofFirst()->create();

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('lets staff set approval_order on a DRAFT quote', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('refuses to change approval_order once sent for ordinary staff', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    Auth::login($staff);
    $quote = Quote::factory()->sent()->create();

    expect(fn () => app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('lets a superadmin change approval_order after send', function (): void {
    $admin = User::factory()->superadmin()->create();
    Auth::login($admin);
    $quote = Quote::factory()->sent()->create();

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});
