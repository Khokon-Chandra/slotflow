<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Booking\BookingData;
use App\Domain\Booking\BookingService;
use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Bookings
 *
 * Creating a booking is public — a customer should not need an account to
 * make an appointment. Listing and cancelling require a token.
 */
final class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * List my bookings
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $user = $request->user();

        $bookings = Booking::query()
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->when(
                $user->isStaff(),
                fn ($q) => $q->where('staff_id', $user->staffProfile?->id),
            )
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('starts_at')
            ->paginate($request->integer('per_page', 25));

        return BookingResource::collection($bookings);
    }

    /**
     * Create a booking
     *
     * Returns 409 `slot_unavailable` if the slot went between the customer
     * seeing it and confirming it — the normal race, not an error condition.
     * Retry against fresh availability.
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create(
            BookingData::fromArray(
                $request->validated(),
                $request->user() !== null ? BookingSource::Api : BookingSource::Web,
            )
        );

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a booking
     *
     * Looked up by reference (e.g. `BL-7Q4M2X`). A guest may read their own
     * booking by also passing the email it was made with — enough to be
     * useful in a confirmation link, not enough to enumerate.
     */
    public function show(Request $request, string $reference): BookingResource
    {
        /** @var Booking $booking */
        $booking = Booking::query()
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->where('reference', $reference)
            ->firstOrFail();

        $user = $request->user();

        if ($user !== null) {
            $this->authorize('view', $booking);
        } else {
            abort_unless(
                hash_equals(
                    mb_strtolower($booking->customer->email),
                    mb_strtolower((string) $request->query('email')),
                ),
                404,
            );
        }

        return new BookingResource($booking);
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('cancel', $booking);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return new BookingResource(
            $this->bookings->cancel($booking, $validated['reason'] ?? null)
        );
    }
}
