<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
final class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'duration_minutes' => $this->duration_minutes,
            'buffer_minutes' => $this->buffer_minutes,
            // Minor units and the currency code, never a pre-formatted string.
            // Formatting money is the client's job; it knows the locale.
            'price_cents' => $this->price_cents,
            'currency' => $this->tenantModel()->currency,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'requires_deposit' => $this->requires_deposit,
            'deposit_cents' => $this->deposit_cents,
            'staff' => StaffResource::collection($this->whenLoaded('staff')),
        ];
    }
}
