<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Builds a minimal, predictable business for a test.
 *
 * Tests that each hand-roll a tenant, a service, a staff member and a set of
 * working hours spend most of their lines on setup and bury the assertion.
 * This keeps the arrangement to one call and the interesting part visible.
 */
final class StudioFactory
{
    public Tenant $tenant;

    public Service $service;

    public Staff $staff;

    public function __construct(
        string $timezone = 'Europe/Vienna',
        int $durationMinutes = 60,
        int $bufferMinutes = 0,
    ) {
        $this->tenant = Tenant::factory()->create([
            'timezone' => $timezone,
            'settings' => [
                'booking' => [
                    // Zero notice by default so a test can book "tomorrow at
                    // 09:00" without fighting a rule it is not testing.
                    'min_notice_minutes' => 0,
                    'max_advance_days' => 365,
                    'slot_granularity_minutes' => 15,
                    'cancellation_window_hours' => 12,
                ],
            ],
        ]);

        app(TenantContext::class)->set($this->tenant);

        $this->staff = Staff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => $timezone,
        ]);

        $this->service = Service::factory()
            ->lasting($durationMinutes, $bufferMinutes)
            ->create(['tenant_id' => $this->tenant->id]);

        $this->service->staff()->attach($this->staff);
    }

    /**
     * Give the staff member working hours.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $shifts  [weekday, start, end]
     */
    public function withHours(array $shifts): self
    {
        foreach ($shifts as [$weekday, $start, $end]) {
            AvailabilityRule::factory()->create([
                'tenant_id' => $this->tenant->id,
                'staff_id' => $this->staff->id,
                'weekday' => $weekday,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }

        return $this;
    }

    /** Working hours every day of the week. */
    public function openEveryDay(string $start = '09:00', string $end = '17:00'): self
    {
        return $this->withHours(
            array_map(fn (int $weekday) => [$weekday, $start, $end], range(0, 6)),
        );
    }

    public function owner(): User
    {
        return User::factory()->owner()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => $this->tenant->timezone,
        ]);
    }

    public function staffUser(): User
    {
        $user = User::factory()->staff()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => $this->tenant->timezone,
        ]);

        $this->staff->update(['user_id' => $user->id]);

        return $user;
    }

    public function customerUser(): User
    {
        return User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::Customer,
        ]);
    }

    /** A second staff member who also performs the service. */
    public function addColleague(string $timezone = 'Europe/Vienna'): Staff
    {
        $colleague = Staff::factory()->create([
            'tenant_id' => $this->tenant->id,
            'timezone' => $timezone,
        ]);

        $this->service->staff()->attach($colleague);

        return $colleague;
    }
}
