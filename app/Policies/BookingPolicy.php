<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Who may see and change a booking.
 *
 * The tenant check is belt-and-braces: the global scope already prevents a
 * cross-tenant record from being loaded. Authorisation that relies on a query
 * scope having been applied is authorisation that disappears the first time
 * someone calls `withoutTenantScope()`, so it is repeated here.
 *
 * ── What this class is not ───────────────────────────────────────────────────
 *
 * It answers *who may act*, never *what move is legal*. Whether a completed
 * booking can go back to confirmed is a question for BookingStatus, and
 * BookingService raises `invalid_booking_transition` with the list of moves
 * that are allowed.
 *
 * Folding that into the policy is tempting and wrong: the client then gets 403
 * for a move that was merely illegal, which reads as "you are not allowed" when
 * it means "that is not a thing" — and it hides the useful part of the answer.
 */
final class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdminArea();
    }

    public function view(User $user, Booking $booking): bool
    {
        if (! $this->sameTenant($user, $booking)) {
            return false;
        }

        if ($user->isOwner()) {
            return true;
        }

        // A staff member sees their own diary, not the whole business.
        if ($user->isStaff()) {
            return $booking->staff_id === $user->staffProfile?->id;
        }

        // A customer sees their own bookings.
        return $booking->customer->user_id === $user->id;
    }

    public function update(User $user, Booking $booking): bool
    {
        if (! $this->sameTenant($user, $booking)) {
            return false;
        }

        return $user->isOwner() || $booking->staff_id === $user->staffProfile?->id;
    }

    /**
     * Customers may cancel their own booking, but only outside the
     * cancellation window — inside it they have to talk to a human, which is
     * the business rule, not a technical limitation.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        if (! $this->sameTenant($user, $booking)) {
            return false;
        }

        if ($user->canAccessAdminArea()) {
            return $this->update($user, $booking);
        }

        return $booking->customer->user_id === $user->id
            && $booking->isWithinCancellationWindow();
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->isOwner() && $this->sameTenant($user, $booking);
    }

    private function sameTenant(User $user, Booking $booking): bool
    {
        return $user->tenant_id === $booking->tenant_id;
    }
}
