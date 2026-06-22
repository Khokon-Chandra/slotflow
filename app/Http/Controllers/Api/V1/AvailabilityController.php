<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Availability\Slot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

/**
 * Availability
 *
 * Free slots, computed from staff working rules minus time off minus existing
 * bookings. Nothing is pre-generated, so the answer is always current.
 */
final class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * Find free slots
     *
     * `tz` is required and every returned slot carries both the UTC instant
     * and its rendering in that zone. Slots are grouped by local date, which
     * is what a calendar UI needs and what a date boundary in the caller's
     * zone actually means.
     */
    public function index(AvailabilityRequest $request): JsonResponse
    {
        /** @var Service $service */
        $service = Service::query()->active()->findOrFail($request->integer('service_id'));

        $query = AvailabilityQuery::fromRequest($service, $request->validated());
        $slots = $this->engine->find($query);

        $grouped = [];

        foreach ($slots as $slot) {
            $grouped[$slot->localStart()->toDateString()][] = $slot->toArray();
        }

        return response()->json([
            'data' => [
                'service' => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'duration_minutes' => $service->duration_minutes,
                ],
                'timezone' => $query->timezone,
                'from' => $query->fromDate->toDateString(),
                'until' => $query->untilDate->toDateString(),
                'slot_count' => count($slots),
                'days' => array_map(
                    fn (string $date) => [
                        'date' => $date,
                        'slots' => $grouped[$date],
                    ],
                    array_keys($grouped),
                ),
            ],
            'meta' => [
                // Told, not implied. A client that caches responses needs to
                // know the server already did, and for how long.
                'cache_ttl_seconds' => (int) config('slotflow.availability.cache_ttl'),
                'min_notice_minutes' => (int) $service->tenantModel()->setting('booking.min_notice_minutes'),
                'max_advance_days' => (int) $service->tenantModel()->setting('booking.max_advance_days'),
            ],
        ]);
    }
}
