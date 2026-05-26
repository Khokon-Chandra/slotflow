<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $timezone
 * @property string|null $notes
 * @property int $completed_count
 * @property int $no_show_count
 * @property int $cancelled_count
 */
#[Fillable([
    'tenant_id', 'user_id', 'name', 'email', 'phone', 'timezone', 'notes',
    'completed_count', 'no_show_count', 'cancelled_count',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'completed_count' => 'integer',
            'no_show_count' => 'integer',
            'cancelled_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Appointments this customer has actually been held accountable for —
     * the denominator of the no-show rate. Excludes bookings still in the
     * future and ordinary cancellations made in good time.
     */
    public function resolvedAppointments(): int
    {
        return $this->completed_count + $this->no_show_count;
    }

    public function noShowRate(): float
    {
        $resolved = $this->resolvedAppointments();

        return $resolved === 0 ? 0.0 : $this->no_show_count / $resolved;
    }

    public function isNewCustomer(): bool
    {
        return $this->resolvedAppointments() === 0;
    }
}
