<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\BookingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin · bookings
 *
 * The business-wide diary. Requires an owner or staff token.
 */
final class AdminBookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * Search the diary
     *
     * Filter by `status`, `staff_id`, `service_id`, `risk`, and a `from`/`to`
     * date range. Results are paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $bookings = Booking::query()
            // Every list view eager-loads. The alternative is four extra
            // queries per row, which is invisible on seed data and fatal at
            // 50,000 bookings.
            //
            // Full rows rather than a column list: the resources render more
            // fields than a list view needs today, and a `select` that drifts
            // out of step with them fails at runtime rather than at compile
            // time. Four small joined rows are not the bottleneck here.
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('service_id'), fn ($q) => $q->where('service_id', $request->integer('service_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<=', $request->date('to')->endOfDay()))
            ->when(
                $request->filled('risk'),
                fn ($q) => $q->whereHas('riskAssessment', fn ($r) => $r->where('band', $request->string('risk')->toString())),
            )
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(function ($inner) use ($request): void {
                    $term = '%'.$request->string('search')->toString().'%';

                    $inner->where('reference', 'like', $term)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('email', 'like', $term));
                }),
            )
            ->orderBy('starts_at', $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    /**
     * Change a booking's status
     *
     * Transitions are validated against the state machine on BookingStatus;
     * an illegal move returns 422 `invalid_booking_transition` with the list
     * of moves that *are* allowed.
     */
    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): BookingResource
    {
        return new BookingResource(
            $this->bookings->transition(
                $booking,
                $request->status(),
                $request->input('reason'),
            )
        );
    }
}
