<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request, before anything can query a tenant-owned
 * table.
 *
 * Resolution order, most trustworthy first:
 *
 *   1. the authenticated user's own tenant — cannot be spoofed
 *   2. an `X-Tenant` header — how an API client selects a business
 *   3. a `tenant` query parameter — convenience for the public booking page
 *
 * If a request is authenticated *and* names a different tenant, that is not a
 * routing quirk, it is someone probing. It gets a 403, not a silent
 * correction — an attempt worth logging is worth refusing.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $requested = $this->requestedSlug($request);

        if ($user !== null) {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->findOrFail($user->tenant_id);

            if ($requested !== null && $requested !== $tenant->slug) {
                abort(403, 'You are not a member of that workspace.');
            }

            $this->context->set($tenant);

            return $next($request);
        }

        if ($requested === null) {
            abort(400, 'No workspace was specified. Send an X-Tenant header or a ?tenant= parameter.');
        }

        $tenant = Tenant::query()->where('slug', $requested)->first();

        if ($tenant === null) {
            abort(404, 'Unknown workspace.');
        }

        $this->context->set($tenant);

        return $next($request);
    }

    private function requestedSlug(Request $request): ?string
    {
        $slug = $request->header('X-Tenant')
            ?? $request->query('tenant')
            ?? $request->route('tenant');

        if ($slug instanceof Tenant) {
            return $slug->slug;
        }

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
