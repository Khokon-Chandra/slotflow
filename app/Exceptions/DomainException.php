<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base class for expected, meaningful failures — a taken slot, an illegal
 * status change, a request outside the booking window.
 *
 * These are not bugs, so they must not surface as a 500. Each one renders
 * itself into the same envelope every other API error uses, so a client can
 * branch on `error.code` instead of parsing prose.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Stable, machine-readable identifier. Part of the API contract:
     * changing one of these is a breaking change.
     */
    abstract public function errorCode(): string;

    public function status(): int
    {
        return 422;
    }

    /**
     * Extra machine-readable context, merged into the error object.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'error' => array_filter([
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
                'context' => $this->context() ?: null,
            ], fn ($value) => $value !== null),
        ], $this->status());
    }
}
