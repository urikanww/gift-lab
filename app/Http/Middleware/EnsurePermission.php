<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on a granular "section.action" permission.
 *
 * Only staff_admin is subject to the granular allowlist: superadmin passes
 * (they hold everything) and any non-staff_admin (a buyer on a shared endpoint)
 * passes here so their OWN policy/tenancy checks decide - this middleware exists
 * to restrict staff, not to authorise buyers. A staff_admin without the named
 * permission gets a 403.
 *
 * Usage: ->middleware('permission:quotes.edit')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user !== null && $user->role === UserRole::StaffAdmin && ! $user->hasPermission($permission)) {
            abort(403, 'You do not have access to this action.');
        }

        return $next($request);
    }
}
