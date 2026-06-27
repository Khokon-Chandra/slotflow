<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Risk\Contracts\RiskNarrator;
use App\Models\Booking;
use App\Models\BookingRiskAssessment;
use Carbon\CarbonImmutable;

/**
 * Scores how likely a booking is to be a no-show.
 *
 * ── Why a language model does not compute this number ────────────────────────
 *
 * No-shows cost a small service business real money, and the actions taken in
 * response — asking for a deposit, double-booking a slot, phoning someone the
 * day before — affect real customers. A number that decides that has to be
 * reproducible, auditable and testable. A model asked to "rate this booking
 * 0-100" gives none of those: the same input can score 40 today and 55
 * tomorrow, and nobody can say why.
 *
 * So the arithmetic here is ordinary, boring PHP with a unit test per factor.
 * The model's job is the part it is actually good at: reading the factors and
 * writing the sentence a receptionist reads at 8am. If the API is down, or no
 * key is configured, the score is unchanged and a template writes the sentence
 * instead — see App\Ai\Narrators.
 *
 * The weights below are illustrative defaults chosen to be explainable, not
 * the output of fitting a model to real data. On a live deployment they are
 * the thing you would replace first, once the business has enough history to
 * fit them properly. That is stated in the admin UI too, so nobody mistakes a
 * heuristic for a prediction.
 */
final class NoShowRiskScorer
{
    public function __construct(
        private readonly RiskNarrator $narrator,
    ) {}

    /**
     * Compute the profile. Pure: no writes, no network, no clock beyond the
     * booking's own times.
     */
    public function score(Booking $booking): RiskProfile
    {
        $customer = $booking->customer;
        $service = $booking->service;
        $localStart = $booking->startsAtForCustomer();

        $factors = [];

        // ── History ──────────────────────────────────────────────────────────
        // The strongest real signal in every study of appointment no-shows is
        // simply whether this person has missed one before.
        $resolved = $customer->resolvedAppointments();

        if ($resolved === 0) {
            $factors[] = new RiskFactor(
                'first_time_customer',
                'First booking — no history to go on',
                18,
            );
        } else {
            $rate = $customer->noShowRate();

            if ($rate > 0) {
                // The heaviest single factor, and deliberately heavy enough to
                // reach the high band on its own. Someone who has missed most
                // of their appointments is the case this feature exists for; a
                // weighting that caps them at "watch" alongside every
                // first-time customer is a weighting that tells you nothing.
                $factors[] = new RiskFactor(
                    'prior_no_show_rate',
                    sprintf('Missed %d of %d past appointments', $customer->no_show_count, $resolved),
                    (int) round(min(50, $rate * 55)),
                );
            }

            // Rate and count say different things. One miss out of two is a
            // bad rate on thin evidence; two misses out of ten is a habit.
            if ($customer->no_show_count >= 2) {
                $factors[] = new RiskFactor(
                    'repeat_no_shows',
                    sprintf('Has missed %d appointments in total', $customer->no_show_count),
                    12,
                );
            }

            if ($customer->completed_count >= 3 && $customer->no_show_count === 0) {
                $factors[] = new RiskFactor(
                    'reliable_regular',
                    sprintf('Attended all %d previous appointments', $customer->completed_count),
                    -15,
                );
            }
        }

        if ($customer->cancelled_count >= 3) {
            $factors[] = new RiskFactor(
                'frequent_canceller',
                sprintf('Cancelled %d bookings previously', $customer->cancelled_count),
                6,
            );
        }

        // ── Lead time ────────────────────────────────────────────────────────
        // Booked months out and forgotten, or booked in a hurry and
        // reconsidered. The middle is the safe zone.
        $leadHours = (int) $booking->created_at?->diffInHours($booking->starts_at, absolute: true)
            ?: (int) CarbonImmutable::now()->diffInHours($booking->starts_at, absolute: true);

        $factors[] = match (true) {
            $leadHours >= 24 * 21 => new RiskFactor('very_long_lead', 'Booked more than three weeks ahead', 12),
            $leadHours >= 24 * 10 => new RiskFactor('long_lead', 'Booked over a week ahead', 6),
            $leadHours <= 4 => new RiskFactor('very_short_lead', 'Booked at short notice', 5),
            default => new RiskFactor('healthy_lead', 'Booked within a comfortable window', -4),
        };

        // ── When in the day ──────────────────────────────────────────────────
        $hour = $localStart->hour;

        if ($hour < 10) {
            $factors[] = new RiskFactor('early_slot', 'Early morning slot', 8);
        } elseif ($hour >= 18) {
            $factors[] = new RiskFactor('evening_slot', 'Evening slot after work', 5);
        }

        if ($localStart->isMonday()) {
            $factors[] = new RiskFactor('monday', 'Monday appointment', 5);
        } elseif ($localStart->isSaturday()) {
            $factors[] = new RiskFactor('saturday', 'Weekend appointment', 4);
        }

        // ── Commitment ───────────────────────────────────────────────────────
        if ($service->requires_deposit && $service->deposit_cents > 0) {
            $factors[] = new RiskFactor('deposit_taken', 'Deposit held against the booking', -20);
        } elseif ($service->price_cents === 0) {
            $factors[] = new RiskFactor('free_service', 'Free service — nothing at stake', 8);
        }

        // ── Reachability ─────────────────────────────────────────────────────
        // Not a prediction about the person; a statement about whether a
        // reminder can reach them at all.
        if (blank($customer->phone)) {
            $factors[] = new RiskFactor('no_phone', 'No phone number for a reminder', 10);
        }

        return RiskProfile::fromFactors($factors);
    }

    /**
     * Score, ask the narrator for the human sentence, and persist.
     */
    public function scoreAndStore(Booking $booking): BookingRiskAssessment
    {
        $booking->loadMissing(['customer', 'service', 'staff']);

        $profile = $this->score($booking);
        $narration = $this->narrator->narrate($booking, $profile);

        return BookingRiskAssessment::updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'tenant_id' => $booking->tenant_id,
                'score' => $profile->score,
                'band' => $profile->band,
                'factors' => $profile->toArray(),
                'rationale' => $narration['rationale'],
                'recommended_action' => $narration['recommended_action'],
                'generated_by' => $narration['driver'],
                'model' => $narration['model'],
            ],
        );
    }
}
