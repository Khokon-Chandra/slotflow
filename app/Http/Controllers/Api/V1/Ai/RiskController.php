<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Domain\Risk\NoShowRiskScorer;
use App\Http\Controllers\Controller;
use App\Http\Resources\RiskAssessmentResource;
use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * AI · no-show risk
 */
final class RiskController extends Controller
{
    public function __construct(private readonly NoShowRiskScorer $scorer) {}

    /**
     * Risk for a booking
     *
     * The score and factors are deterministic — the same booking always scores
     * the same. Only `rationale` and `recommended_action` are model-written,
     * and `generated_by` says which of the two produced them.
     *
     * Pass `refresh=1` to recompute (the customer's history may have moved).
     */
    public function __invoke(Request $request, Booking $booking): RiskAssessmentResource
    {
        $this->authorize('view', $booking);

        $assessment = $request->boolean('refresh') || $booking->riskAssessment === null
            ? $this->scorer->scoreAndStore($booking)
            : $booking->riskAssessment;

        return new RiskAssessmentResource($assessment);
    }
}
