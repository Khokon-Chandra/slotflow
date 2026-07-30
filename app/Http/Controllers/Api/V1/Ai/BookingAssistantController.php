<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Assistants\BookingAssistant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookingAssistantRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * AI · booking assistant
 *
 * Turns a sentence into real, bookable slots.
 */
final class BookingAssistantController extends Controller
{
    public function __construct(
        private readonly BookingAssistant $assistant,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Suggest slots from free text
     *
     * Send what the customer typed. The response contains the parsed intent,
     * the matched service and up to `limit` slots that are genuinely free —
     * they come from the availability engine, not from the model.
     *
     * Booking one still requires `POST /bookings`. This endpoint never writes.
     *
     * The `ai` object reports which driver answered (`claude` or `heuristic`)
     * and why, if it degraded. Show that to the user rather than hiding it.
     */
    public function __invoke(BookingAssistantRequest $request): JsonResponse
    {
        $result = $this->assistant->suggest(
            tenant: $this->tenants->require(),
            text: $request->string('text')->toString(),
            timezone: $request->string('tz')->toString(),
            limit: $request->integer('limit', 6),
        );

        return response()->json(['data' => $result]);
    }
}
