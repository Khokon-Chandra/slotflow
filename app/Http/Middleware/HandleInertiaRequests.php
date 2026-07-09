<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Ai\AiManager;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Data every Inertia page gets for free.
 *
 * `ai.live` is here on purpose: the frontend labels model-written content
 * differently from template-written content, and it can only do that if it
 * knows which mode the server is in.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            /*
             * Every one of these is a closure.
             *
             * Inertia's middleware runs `share()` on the way *in*, before the
             * route's own middleware has run — so at this point the tenant has
             * not been resolved yet and reading it eagerly yields null. A
             * closure is evaluated when the response is rendered, by which
             * time it has. The bug this avoids is quiet: the page renders,
             * just with an empty header.
             */
            'auth' => fn (): array => [
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role->value,
                    'timezone' => $request->user()->timezone,
                    'is_admin' => $request->user()->isOwner(),
                ],
            ],

            'tenant' => function (): ?array {
                $tenant = app(TenantContext::class)->get();

                return $tenant === null ? null : [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'currency' => $tenant->currency,
                ];
            },

            // The frontend labels model-written content differently from
            // template-written content. It can only do that if it knows which
            // mode the server is running in.
            'ai' => fn (): array => [
                'live' => app(AiManager::class)->isLive(),
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
