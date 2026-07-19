<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedAddress;
use App\Models\User;

/**
 * A saved address is personal: only its owner may read, edit, or delete it.
 * There is no staff override - staff manage the per-quote ShippingAddress, not
 * a buyer's private book.
 */
class SavedAddressPolicy
{
    public function view(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function update(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }

    public function delete(User $user, SavedAddress $address): bool
    {
        return $user->id === $address->user_id;
    }
}
