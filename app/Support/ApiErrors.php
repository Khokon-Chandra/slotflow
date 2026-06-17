<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Helpers for the exception handlers in bootstrap/app.php.
 */
final class ApiErrors
{
    /**
     * Whether this request should get a JSON error body rather than an HTML
     * error page. The admin panel is an Inertia app served over `web`, so
     * "is it under /api" is not the same question as "does it want JSON".
     */
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
