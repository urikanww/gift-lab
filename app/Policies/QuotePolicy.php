<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quote;
use App\Models\User;

/**
 * Tenancy isolation: a buyer may only touch their own company's quotes; staff
 * see everything. This backs both HTTP authorization and the broadcast channel
 * auth for company.{id}.
 */
class QuotePolicy
{
    /**
     * Create a quote for a company. Staff may raise a quote on any company's
     * behalf; a buyer only for their own company. Defense-in-depth net that
     * mirrors StoreQuoteRequest's tenancy check at the policy layer, so a new
     * entry point can't create cross-company quotes by skipping the FormRequest.
     */
    public function create(User $user, int $companyId): bool
    {
        return $user->isStaff() || $user->company_id === $companyId;
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->isStaff() || $user->company_id === $quote->company_id;
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->isStaff() || $user->company_id === $quote->company_id;
    }

    public function amend(User $user, Quote $quote): bool
    {
        return $user->isStaff();
    }

    public function manageProduction(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Sign a proof off: the owning-company buyer (their own approval) or a
     * superadmin (on the buyer's behalf). Deliberately NOT any staff_admin -
     * on-behalf approval is a superadmin action (mirrors the UI).
     */
    public function approveOnBehalf(User $user, Quote $quote): bool
    {
        return $user->company_id === $quote->company_id || $user->isSuperadmin();
    }
}
