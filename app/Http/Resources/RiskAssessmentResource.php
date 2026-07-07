<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BookingRiskAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingRiskAssessment
 */
final class RiskAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'score' => $this->score,
            'band' => $this->band->value,
            'band_label' => $this->band->label(),
            // The full arithmetic ships with the score. A client that wants to
            // show only the number can ignore it; a client that wants to
            // justify a deposit request cannot get it any other way.
            'factors' => $this->factors,
            'rationale' => $this->rationale,
            'recommended_action' => $this->recommended_action,
            'generated_by' => $this->generated_by,
            'model' => $this->model,
            'generated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
