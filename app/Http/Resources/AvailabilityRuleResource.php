<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AvailabilityRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AvailabilityRule
 */
final class AvailabilityRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'weekday' => $this->weekday,
            // Wall-clock times, trimmed to HH:MM. The staff timezone they
            // belong to travels with the staff member, not with each rule.
            'starts_at' => substr((string) $this->starts_at, 0, 5),
            'ends_at' => substr((string) $this->ends_at, 0, 5),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
        ];
    }
}
