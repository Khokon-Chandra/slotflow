<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $reference
 * @property int $service_id
 * @property int $staff_id
 * @property int $customer_id
 * @property CarbonImmutable $starts_at UTC
 * @property CarbonImmutable $ends_at UTC — what the customer is told
 * @property CarbonImmutable $blocks_until UTC — what the diary reserves
 * @property BookingStatus $status
 * @property BookingSource $source
 * @property string $customer_timezone
 * @property int $price_cents
 * @property string|null $notes
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $reminder_sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service $service
 * @property-read Staff $staff
 * @property-read Customer $customer
 * @property-read BookingRiskAssessment|null $riskAssessment
 */
/*
 * Mass-assignable columns are the ones BookingService writes when it creates a
 * booking, and no more. `cancelled_at`, `completed_at` and
 * `cancellation_reason` are deliberately absent: they are set by the status
 * transition, one property at a time, so no request body can ever reach them.
 */
#[Fillable([
    'tenant_id', 'reference', 'service_id', 'staff_id', 'customer_id',
    'starts_at', 'ends_at', 'blocks_until', 'status', 'source',
    'customer_timezone', 'price_cents', 'notes', 'confirmed_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'price_cents' => 'integer',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function riskAssessment(): HasOne
    {
        return $this->hasOne(BookingRiskAssessment::class);
    }

    /**
     * Start time rendered in the timezone the customer booked in.
     */
    public function startsAtForCustomer(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->customer_timezone);
    }

    public function isUpcoming(): bool
    {
        return $this->starts_at->isFuture();
    }

    /**
     * Whether the customer may still cancel without contacting the business.
     */
    public function isWithinCancellationWindow(): bool
    {
        $hours = (int) $this->tenantModel()->setting('booking.cancellation_window_hours', 12);

        return $this->starts_at->diffInHours(CarbonImmutable::now(), absolute: false) > -$hours;
    }

    #[Scope]
    protected function blocking(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::blocking());
    }

    #[Scope]
    protected function upcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', CarbonImmutable::now());
    }

    #[Scope]
    protected function between(Builder $query, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return $query->where('starts_at', '>=', $from)->where('starts_at', '<', $until);
    }

    /**
     * Half-open overlap against an arbitrary window — the predicate the
     * double-booking guard runs inside its transaction.
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return $query
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from);
    }
}
