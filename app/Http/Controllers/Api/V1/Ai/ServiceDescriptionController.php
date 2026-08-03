<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Tasks\WriteServiceDescription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceDescriptionRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * AI · service copy
 */
final class ServiceDescriptionController extends Controller
{
    public function __construct(
        private readonly WriteServiceDescription $writer,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Draft a service description
     *
     * Returns a draft for a human to edit. It is not saved anywhere — the
     * owner still has to accept it in the form.
     */
    public function __invoke(ServiceDescriptionRequest $request): JsonResponse
    {
        return response()->json([
            'data' => ($this->writer)($this->tenants->require(), $request->validated()),
        ]);
    }
}
