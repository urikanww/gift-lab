<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Single serialization of the authenticated user for the SPA. Shared by the
 * password and Google auth controllers so /login, /register and the Google
 * completion endpoint all return an identical `user` shape - a divergence here
 * would make the frontend's stored user depend on which path signed them in.
 *
 * Embeds a minimal company summary (id/name/address) so the storefront can show
 * the buyer where an order ships - read-only, reusing the company's stored
 * address. Only the fields the SPA needs are exposed.
 */
final class AuthUserPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user): array
    {
        $user->loadMissing('company');

        return [
            'id' => $user->id,
            'company_id' => $user->company_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            // Effective granular access, so the console can hide sections a
            // staff_admin has not been granted. Superadmin resolves to all;
            // buyers to none. See App\Support\Permissions.
            'permissions' => $user->effectivePermissions(),
            'company' => $user->company === null ? null : [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'address' => $user->company->address,
            ],
        ];
    }
}
