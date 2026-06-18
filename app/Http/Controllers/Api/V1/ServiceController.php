<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreServiceRequest;
use App\Http\Requests\Api\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Services
 *
 * The things a customer can book. Reading is public; writing requires an
 * owner token.
 */
final class ServiceController extends Controller
{
    /**
     * List services
     *
     * Returns active services by default. Owners may pass `include_inactive=1`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $includeInactive = $request->boolean('include_inactive')
            && ($request->user()?->canAccessAdminArea() ?? false);

        $services = Service::query()
            ->unless($includeInactive, fn ($q) => $q->active())
            ->with('staff')
            ->ordered()
            ->get();

        return ServiceResource::collection($services);
    }

    /**
     * Show a service
     */
    public function show(Request $request, Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return new ServiceResource($service->load('staff'));
    }

    /**
     * Create a service
     */
    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::create([
            ...$request->safe()->except('staff_ids'),
            'slug' => $request->input('slug') ?: Str::slug($request->string('name')->toString()),
        ]);

        $service->staff()->sync($request->input('staff_ids', []));

        return (new ServiceResource($service->load('staff')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a service
     */
    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->safe()->except('staff_ids'));

        if ($request->has('staff_ids')) {
            $service->staff()->sync($request->input('staff_ids', []));
        }

        return new ServiceResource($service->load('staff'));
    }

    /**
     * Delete a service
     *
     * Refuses while future bookings exist. Deactivate it instead — deleting a
     * service that people are booked into would orphan their appointments.
     */
    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorize('delete', $service);

        $upcoming = $service->bookings()->blocking()->upcoming()->count();

        if ($upcoming > 0) {
            return response()->json([
                'error' => [
                    'code' => 'service_has_bookings',
                    'message' => "This service has {$upcoming} upcoming booking(s). Deactivate it instead of deleting it.",
                    'context' => ['upcoming_bookings' => $upcoming],
                ],
            ], 409);
        }

        $service->delete();

        return response()->json(status: 204);
    }
}
