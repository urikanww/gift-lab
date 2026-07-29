<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route to superadmin only, as route-level defence-in-depth for actions
 * that are NOT delegable granular permissions - e.g. the auto-publish toggle
 * (L27) and CSV import (L26), which the controllers already restrict to
 * superadmin in-body. Putting the gate on the route too means the requirement
 * is visible at the route table and enforced before the controller runs,
 * instead of relying solely on an in-controller check (and, for import, instead
 * of a misleading `permission:products.edit` that let a products.edit staff
 * through the route only to 403 deeper in).
 *
 * Usage: ->middleware('superadmin')
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->role !== UserRole::Superadmin) {
            abort(403, 'This action is restricted to superadmins.');
        }

        return $next($request);
    }
}
