<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners and staff reach the admin area; customers do not.
 *
 * This is a coarse gate on the whole area. What each role may actually *do*
 * once inside is decided per record by the policies — a staff member can see
 * the diary but only manage their own bookings.
 */
final class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessAdminArea()) {
            abort(403, 'This area is for the business team.');
        }

        return $next($request);
    }
}
