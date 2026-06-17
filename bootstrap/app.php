<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\ResolveTenant;
use App\Support\ApiErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'admin' => EnsureAdminAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        |----------------------------------------------------------------------
        | One error shape, everywhere
        |----------------------------------------------------------------------
        |
        | Out of the box, a validation failure returns {message, errors}, a
        | missing model returns {message}, and a domain exception returns
        | whatever it feels like. Three shapes means every client writes three
        | parsers and gets the third one wrong.
        |
        | Everything below normalises to:
        |
        |     { "error": { "code": "...", "message": "...", "fields": {...} } }
        |
        | `code` is the stable part and the part clients should branch on.
        | Domain exceptions render themselves (App\Exceptions\DomainException);
        | these handlers cover the framework's own.
        */

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ApiErrors::wantsJson($request)) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The request could not be validated.',
                    'fields' => $e->errors(),
                ],
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! ApiErrors::wantsJson($request)) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'A valid bearer token is required for this endpoint.',
                ],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! ApiErrors::wantsJson($request)) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => $e->getMessage() ?: 'You do not have permission to do that.',
                ],
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! ApiErrors::wantsJson($request)) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    // Never echo the model class back. "App\Models\Booking not
                    // found" tells a caller the shape of the internals for no
                    // benefit to them.
                    'message' => 'That resource does not exist, or you cannot see it.',
                ],
            ], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ApiErrors::wantsJson($request) || $e->getStatusCode() < 400) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => match ($e->getStatusCode()) {
                        400 => 'bad_request',
                        403 => 'forbidden',
                        404 => 'not_found',
                        409 => 'conflict',
                        429 => 'too_many_requests',
                        default => 'http_error',
                    },
                    'message' => $e->getMessage() ?: 'The request could not be completed.',
                ],
            ], $e->getStatusCode());
        });
    })->create();
