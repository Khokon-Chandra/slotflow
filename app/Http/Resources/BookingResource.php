<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The caller may ask for a rendering timezone; otherwise the booking
        // is shown in the clock the customer booked in. Never the server's.
        $timezone = $request->query('tz', $this->customer_timezone);

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source->value,

            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'timezone' => $timezone,
            'local_starts_at' => $this->starts_at->setTimezone($timezone)->toIso8601String(),
            'local_ends_at' => $this->ends_at->setTimezone($timezone)->toIso8601String(),

            'price_cents' => $this->price_cents,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'service' => new ServiceResource($this->whenLoaded('service')),
            'staff' => new StaffResource($this->whenLoaded('staff')),

            'customer' => $this->when(
                $request->user()?->canAccessAdminArea() ?? false,
                fn () => [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                    'email' => $this->customer->email,
                    'phone' => $this->customer->phone,
                    'completed_count' => $this->customer->completed_count,
                    'no_show_count' => $this->customer->no_show_count,
                ],
            ),

            'risk' => $this->when(
                ($request->user()?->canAccessAdminArea() ?? false) && $this->relationLoaded('riskAssessment') && $this->riskAssessment !== null,
                fn () => new RiskAssessmentResource($this->riskAssessment),
            ),
        ];
    }
}
