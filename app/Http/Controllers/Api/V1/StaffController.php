<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreStaffRequest;
use App\Http\Requests\Api\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Team
 *
 * The people with diaries.
 */
final class StaffController extends Controller
{
    /**
     * List team members
     *
     * Filter to those who perform one service with `service_id`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = Staff::query()
            ->active()
            ->with('services')
            ->when(
                $request->filled('service_id'),
                fn ($q) => $q->whereHas('services', fn ($s) => $s->whereKey($request->integer('service_id'))),
            )
            ->ordered()
            ->get();

        return StaffResource::collection($staff);
    }

    /**
     * Show a team member
     */
    public function show(Request $request, Staff $staff): StaffResource
    {
        $this->authorize('view', $staff);

        return new StaffResource($staff->load(['services', 'availabilityRules']));
    }

    /**
     * Add a team member
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = Staff::create($request->safe()->except('service_ids'));
        $staff->services()->sync($request->input('service_ids', []));

        return (new StaffResource($staff->load('services')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a team member
     */
    public function update(UpdateStaffRequest $request, Staff $staff): StaffResource
    {
        $staff->update($request->safe()->except('service_ids'));

        if ($request->has('service_ids')) {
            $staff->services()->sync($request->input('service_ids', []));
        }

        return new StaffResource($staff->load('services'));
    }

    /**
     * Remove a team member
     *
     * Refuses while future bookings exist, for the same reason as services.
     */
    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        $this->authorize('delete', $staff);

        $upcoming = $staff->bookings()->blocking()->upcoming()->count();

        if ($upcoming > 0) {
            return response()->json([
                'error' => [
                    'code' => 'staff_has_bookings',
                    'message' => "This team member has {$upcoming} upcoming booking(s). Reassign or cancel them first.",
                    'context' => ['upcoming_bookings' => $upcoming],
                ],
            ], 409);
        }

        $staff->delete();

        return response()->json(status: 204);
    }
}
