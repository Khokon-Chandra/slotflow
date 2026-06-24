<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\AvailabilityEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTimeOffRequest;
use App\Http\Resources\TimeOffResource;
use App\Models\Staff;
use App\Models\TimeOff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Time off
 *
 * Absolute intervals subtracted from a staff member's weekly pattern.
 */
final class TimeOffController extends Controller
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * List time off
     */
    public function index(Request $request, Staff $staff): AnonymousResourceCollection
    {
        $this->authorize('view', $staff);

        return TimeOffResource::collection(
            $staff->timeOff()->orderBy('starts_at')->get()
        );
    }

    /**
     * Book time off
     *
     * Existing bookings inside the interval are *not* cancelled automatically.
     * Silently dropping appointments someone has already promised to attend is
     * not a decision an API should make; the conflicts are returned instead so
     * the caller can deal with them.
     */
    public function store(StoreTimeOffRequest $request, Staff $staff): JsonResponse
    {
        $timeOff = $staff->timeOff()->create([
            'tenant_id' => $staff->tenant_id,
            ...$request->validated(),
        ]);

        $this->engine->invalidate($staff->tenant_id);

        $conflicts = $staff->bookings()
            ->blocking()
            ->overlapping($timeOff->starts_at, $timeOff->ends_at)
            ->pluck('reference');

        return response()->json([
            'data' => new TimeOffResource($timeOff),
            'meta' => [
                'conflicting_bookings' => $conflicts->all(),
            ],
        ], 201);
    }

    /**
     * Delete time off
     */
    public function destroy(Request $request, Staff $staff, TimeOff $timeOff): JsonResponse
    {
        $this->authorize('manageAvailability', $staff);
        abort_unless($timeOff->staff_id === $staff->id, 404);

        $timeOff->delete();
        $this->engine->invalidate($staff->tenant_id);

        return response()->json(status: 204);
    }
}
