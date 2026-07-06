<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RiskBand;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int $score
 * @property RiskBand $band
 * @property list<array{code: string, label: string, points: int}> $factors
 * @property string|null $rationale
 * @property string|null $recommended_action
 * @property string $generated_by
 * @property string|null $model
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read Booking $booking
 */
#[Fillable([
    'tenant_id', 'booking_id', 'score', 'band', 'factors',
    'rationale', 'recommended_action', 'generated_by', 'model',
])]
class BookingRiskAssessment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'band' => RiskBand::class,
            'factors' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * True when a language model wrote the rationale, false when the built-in
     * template did. Surfaced in the UI so nobody mistakes one for the other.
     */
    public function isModelWritten(): bool
    {
        return $this->generated_by === 'claude';
    }
}
