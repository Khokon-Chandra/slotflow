<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Availability\TimeRange;
use App\Domain\Risk\NoShowRiskScorer;
use App\Enums\BookingStatus;
use App\Exceptions\BookingWindowException;
use App\Exceptions\InvalidBookingTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything that writes a booking goes through here — the public API, the
 * admin panel and the AI assistant alike. One place to enforce the rules
 * means there is exactly one place where they can be wrong.
 */
final class BookingService
{
    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly NoShowRiskScorer $risk,
    ) {}

    /**
     * Create a booking, or fail because the slot went.
     *
     * ── Why this is a transaction with an explicit lock ──────────────────────
     *
     * "Check whether the slot is free, then insert" is a read followed by a
     * write, and between the two another request can do exactly the same
     * thing. Both see a free slot; both insert; the customer arrives to find
     * someone else in the chair. Availability caching makes the window wider,
     * not narrower.
     *
     * The fix is to make the check and the write one atomic step. We take a
     * row lock on the *staff member* — the resource actually being contended —
     * so concurrent bookings for the same person serialise here and bookings
     * for different people still run in parallel. The overlap check then runs
     * inside the lock, against committed data, and the insert follows it.
     *
     * Locking the parent row rather than relying on InnoDB gap locks over the
     * bookings range is a deliberate choice: gap-lock behaviour depends on the
     * isolation level and on whether the range matched any rows, which makes
     * the guarantee hard to reason about and hard to test. A named lock on a
     * row that always exists is boring, portable and obvious.
     *
     * `tests/Feature/ConcurrentBookingTest.php` fires two identical requests
     * at the same slot from two processes and asserts that exactly one gets a
     * 201 and the other a 409.
     */
    public function create(BookingData $data): Booking
    {
        $service = Service::query()->findOrFail($data->serviceId);
        $staff = Staff::query()->findOrFail($data->staffId);

        $this->assertBookable($service, $staff, $data->startsAt);

        $block = TimeRange::fromMinutes($data->startsAt, $service->blockingMinutes());

        $booking = DB::transaction(function () use ($data, $service, $staff, $block): Booking {
            // 1. Serialise every write touching this staff member's diary.
            Staff::query()
                ->whereKey($staff->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Re-check for an overlap now that nobody else can be mid-insert.
            //    Half-open comparison, and against `blocks_until` so an
            //    existing appointment's buffer is respected.
            $taken = Booking::query()
                ->where('staff_id', $staff->id)
                ->whereIn('status', BookingStatus::blocking())
                ->where('starts_at', '<', $block->end)
                ->where('blocks_until', '>', $block->start)
                ->exists();

            if ($taken) {
                throw new SlotUnavailableException($data->startsAt, $staff->id);
            }

            // 3. Safe to write.
            $customer = $this->resolveCustomer($data);

            return Booking::create([
                'tenant_id' => $service->tenant_id,
                'reference' => BookingReference::generate($service->tenantModel()),
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'customer_id' => $customer->id,
                'starts_at' => $block->start,
                'ends_at' => $block->start->addMinutes($service->duration_minutes),
                'blocks_until' => $block->end,
                'status' => BookingStatus::Confirmed,
                'source' => $data->source,
                'customer_timezone' => $data->customerTimezone,
                'price_cents' => $service->price_cents,
                'notes' => $data->notes,
                'confirmed_at' => CarbonImmutable::now(),
            ]);
        }, attempts: 3);

        $this->availability->invalidate($service->tenant_id);

        // Score outside the transaction: it reads the customer's history and
        // must never be able to hold a diary lock open.
        $this->risk->scoreAndStore($booking->fresh(['service', 'staff', 'customer']));

        return $booking->fresh(['service', 'staff', 'customer', 'riskAssessment']);
    }

    /**
     * Move a booking to a new status, honouring the transition table on
     * BookingStatus and keeping the customer's counters in step.
     */
    public function transition(Booking $booking, BookingStatus $target, ?string $reason = null): Booking
    {
        if ($booking->status === $target) {
            return $booking;
        }

        if (! $booking->status->canTransitionTo($target)) {
            throw new InvalidBookingTransitionException($booking->status, $target);
        }

        DB::transaction(function () use ($booking, $target, $reason): void {
            $booking->status = $target;

            match ($target) {
                BookingStatus::Confirmed => $booking->confirmed_at = CarbonImmutable::now(),
                BookingStatus::Completed => $booking->completed_at = CarbonImmutable::now(),
                BookingStatus::Cancelled => tap($booking, function (Booking $b) use ($reason): void {
                    $b->cancelled_at = CarbonImmutable::now();
                    $b->cancellation_reason = $reason;
                }),
                default => null,
            };

            $booking->save();

            // The denormalised counters on `customers` are what the risk
            // scorer reads. Updating them in the same transaction as the
            // status change is what keeps them true.
            $customer = $booking->customer;

            match ($target) {
                BookingStatus::Completed => $customer->increment('completed_count'),
                BookingStatus::NoShow => $customer->increment('no_show_count'),
                BookingStatus::Cancelled => $customer->increment('cancelled_count'),
                default => null,
            };
        });

        $this->availability->invalidate($booking->tenant_id);

        return $booking->fresh(['service', 'staff', 'customer', 'riskAssessment']);
    }

    public function cancel(Booking $booking, ?string $reason = null): Booking
    {
        return $this->transition($booking, BookingStatus::Cancelled, $reason);
    }

    /**
     * Guard the rules that do not need a lock: they depend only on the request
     * and on rarely-changing configuration, so checking them before opening a
     * transaction keeps the lock window as short as possible.
     */
    private function assertBookable(Service $service, Staff $staff, CarbonImmutable $startsAt): void
    {
        if (! $service->is_active) {
            throw BookingWindowException::serviceUnavailable();
        }

        if (! $staff->is_active || ! $staff->services()->whereKey($service->id)->exists()) {
            throw BookingWindowException::staffCannotPerformService();
        }

        $tenant = $service->tenantModel();

        $minNotice = (int) $tenant->setting('booking.min_notice_minutes', 120);
        if ($startsAt->lessThan(CarbonImmutable::now()->addMinutes($minNotice))) {
            throw BookingWindowException::tooSoon($minNotice);
        }

        $maxAdvance = (int) $tenant->setting('booking.max_advance_days', 60);
        if ($startsAt->greaterThan(CarbonImmutable::now()->addDays($maxAdvance)->endOfDay())) {
            throw BookingWindowException::tooFarAhead($maxAdvance);
        }

        // The slot must land inside published working hours and outside any
        // time off. The transactional guard below covers double-booking; this
        // covers "10pm on a Sunday".
        if (! $this->availability->isSlotFree($service, $staff, $startsAt)) {
            throw new SlotUnavailableException(
                $startsAt,
                $staff->id,
                'That time is not available for this team member.',
            );
        }
    }

    /**
     * Find or create the customer record. Email is the identity within a
     * tenant — the same person booking a second time keeps their history,
     * which is what makes risk scoring possible at all.
     */
    private function resolveCustomer(BookingData $data): Customer
    {
        if ($data->customerId !== null) {
            return Customer::query()->findOrFail($data->customerId);
        }

        $customer = Customer::query()
            ->where('email', $data->customerEmail)
            ->first();

        if ($customer !== null) {
            $customer->fill(array_filter([
                'name' => $data->customerName,
                'phone' => $data->customerPhone,
                'timezone' => $data->customerTimezone,
            ]))->save();

            return $customer;
        }

        return Customer::create([
            'name' => $data->customerName,
            'email' => $data->customerEmail,
            'phone' => $data->customerPhone,
            'timezone' => $data->customerTimezone,
        ]);
    }
}
