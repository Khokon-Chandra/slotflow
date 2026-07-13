<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant resolution for the browser-facing pages.
 *
 * A signed-in user brings their own workspace. Everyone else lands on the
 * seeded demo workspace, because this deployment is a portfolio piece with one
 * business in it and asking a visitor to type a slug would be theatre.
 *
 * In a real deployment this is one line different: resolve from the subdomain
 * or a custom domain instead of falling back to a constant. The rest of the
 * application never learns which it was.
 */
final class ResolveDemoTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->context->set(Tenant::query()->findOrFail($user->tenant_id));

            return $next($request);
        }

        $slug = $request->query('tenant') ?: config('slotflow.demo.tenant_slug');

        $tenant = Tenant::query()->where('slug', $slug)->first();

        abort_if($tenant === null, 404, 'Unknown workspace.');

        $this->context->set($tenant);

        return $next($request);
    }
}
