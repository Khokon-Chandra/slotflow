<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Risk\RiskProfile;
use App\Models\Booking;

/**
 * Turns a computed risk profile into two sentences a receptionist can act on.
 *
 * The contract lives in the domain and the implementation lives in App\Ai, so
 * the scoring logic has no idea whether a language model, a template or a
 * mechanical turk is on the other side. Swapping one for another is a
 * container binding, not a refactor.
 */
interface RiskNarrator
{
    /**
     * @return array{rationale: string, recommended_action: string, driver: string, model: string|null}
     */
    public function narrate(Booking $booking, RiskProfile $profile): array;
}
