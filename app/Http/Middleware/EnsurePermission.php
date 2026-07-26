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
 *
 * A comma-separated list is an ANY-of check - the staff_admin passes if they
 * hold at least one of the named permissions. This is for routes shared by two
 * sections (e.g. a production-floor stream that both `products.view` catalogue
 * staff and `production.view` floor operators need):
 * ->middleware('permission:products.view,production.view')
 *
 * Note: Laravel's router itself splits the string after the first ':' on
 * commas into separate middleware parameters, so this must be variadic
 * (`string ...$permissions`) rather than a single string manually split on
 * commas - a single-string parameter would silently receive only the first
 * name and drop the rest.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user !== null && $user->role === UserRole::StaffAdmin) {
            $hasAny = false;
            foreach ($permissions as $candidate) {
                if ($user->hasPermission($candidate)) {
                    $hasAny = true;
                    break;
                }
            }

            if (! $hasAny) {
                abort(403, 'You do not have access to this action.');
            }
        }

        return $next($request);
    }
}
