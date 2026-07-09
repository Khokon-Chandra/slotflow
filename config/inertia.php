<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server-side rendering
    |--------------------------------------------------------------------------
    |
    | Off by default, deliberately.
    |
    | Inertia ships with this enabled, which means every page render tries to
    | reach an SSR server on 127.0.0.1:13714 and — because `throw_on_error` is
    | false — silently falls back to client rendering when nothing answers.
    | The page still works, so nobody notices; what it costs is a failed
    | connection attempt on every single request, in production, forever.
    |
    | This application has no SSR build step, so there is nothing to reach.
    | Turning it on means adding an `ssr.ts` entry point, a second Vite build
    | and a Node process to supervise — worth it for a public marketing site
    | that needs indexing, not for an admin panel behind a login.
    |
    | The public booking pages are the ones where it would pay off. If they
    | ever need to rank, set INERTIA_SSR_ENABLED=true and build the bundle.
    |
    */

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Page existence checks
    |--------------------------------------------------------------------------
    |
    | Asserts that a page component actually exists on disk when a controller
    | renders it. It walks the filesystem, so it is on only where a typo should
    | be a failing test rather than a blank screen.
    |
    */

    'pages' => [
        'ensure_pages_exist' => (bool) env('INERTIA_ENSURE_PAGES_EXIST', false),
        'paths' => [resource_path('js/pages')],
        'extensions' => ['vue'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    */

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['vue'],
    ],
];
