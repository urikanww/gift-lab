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
}
