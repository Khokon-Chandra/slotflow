<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Risk\NoShowRiskScorer;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bright Lane Studio — the demo workspace.
 *
 * The data is invented but shaped like a real small business: a handful of
 * services with different lengths, three staff (one of them remote, in another
 * timezone), a year of history with a believable no-show rate, and a diary
 * with gaps in it. Seed data that is too tidy makes a booking system look like
 * it works when it does not — every interesting bug lives in the mess.
 *
 * A second, deliberately unrelated workspace is seeded alongside it so the
 * tenant-isolation tests have something real to fail against.
 */
final class DemoSeeder extends Seeder
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly NoShowRiskScorer $risk,
    ) {}

    public function run(): void
    {
        $tenant = $this->createStudio();

        $this->tenants->runFor($tenant, function () use ($tenant): void {
            [$maya, $tomas, $priya] = $this->createTeam($tenant);
            $services = $this->createServices($tenant, $maya, $tomas, $priya);
            $this->createWorkingHours($maya, $tomas, $priya);
            $this->createTimeOff($maya);

            $customers = $this->createCustomers($tenant);
            $this->createHistory($tenant, $services, [$maya, $tomas, $priya], $customers);
            $this->createUpcoming($tenant, $services, [$maya, $tomas, $priya], $customers);
        });

        $this->createSecondWorkspace();

        $this->say('');
        $this->say('Demo workspace seeded: '.$tenant->name.' ('.$tenant->slug.')');
        $this->say('  Owner     owner@slotflow.test    / password');
        $this->say('  Staff     maya@slotflow.test     / password');
        $this->say('  Customer  customer@slotflow.test / password');
    }

    /**
     * Print a line, if this seeder was started from the console.
     *
     * `Seeder::$command` is unset when a seeder is invoked programmatically —
     * from a test, for instance — so `isset()` is the guard Laravel itself
     * uses internally. A nullsafe call would not help: an uninitialised typed
     * property throws before the operator gets a chance.
     */
    private function say(string $message): void
    {
        if (isset($this->command)) {
            $this->command->getOutput()->writeln($message);
        }
    }

    private function createStudio(): Tenant
    {
        return Tenant::query()->updateOrCreate(
            ['slug' => config('slotflow.demo.tenant_slug')],
            [
                'name' => 'Bright Lane Studio',
                'timezone' => 'Europe/Vienna',
                'currency' => 'EUR',
                'locale' => 'en',
                'contact_email' => 'hello@brightlane.test',
                'phone' => '+43 1 234 5678',
                'description' => 'A small hair and wellness studio near Karlsplatz. Three chairs, no rush.',
                'settings' => [
                    'booking' => [
                        'min_notice_minutes' => 120,
                        'max_advance_days' => 60,
                        'slot_granularity_minutes' => 15,
                        'cancellation_window_hours' => 12,
                    ],
                ],
            ],
        );
    }

    /**
     * @return array{0: Staff, 1: Staff, 2: Staff}
     */
    private function createTeam(Tenant $tenant): array
    {
        $owner = $this->user($tenant, 'Elena Fischer', config('slotflow.demo.owner_email'), UserRole::Owner, 'Europe/Vienna');
        $mayaUser = $this->user($tenant, 'Maya Brenner', config('slotflow.demo.staff_email'), UserRole::Staff, 'Europe/Vienna');
        $this->user($tenant, 'Sam Ali', config('slotflow.demo.customer_email'), UserRole::Customer, 'Asia/Dhaka');

        $maya = Staff::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Maya Brenner'],
            [
                'user_id' => $mayaUser->id,
                'title' => 'Senior stylist',
                'bio' => 'Twelve years behind the chair. Colour corrections and anything involving a fringe.',
                'timezone' => 'Europe/Vienna',
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $tomas = Staff::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Tomás Rivera'],
            [
                'user_id' => null,
                'title' => 'Barber',
                'bio' => 'Cuts, beards, and strong opinions about clippers.',
                'timezone' => 'Europe/Vienna',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        // Deliberately in another timezone. Her published hours are 09:00–14:00
        // *in Kolkata*, which is 05:30–10:30 in Vienna for half the year and
        // 04:30–09:30 for the other half. If the availability engine has a
        // timezone bug, this is where it shows up first.
        $priya = Staff::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Dr. Priya Nair'],
            [
                'user_id' => null,
                'title' => 'Trichologist (remote)',
                'bio' => 'Scalp and hair-loss consultations by video. Based in Bengaluru.',
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
                'sort_order' => 3,
            ],
        );

        // The owner also takes appointments — small businesses do not have the
        // luxury of a purely administrative owner.
        Staff::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Elena Fischer'],
            [
                'user_id' => $owner->id,
                'title' => 'Owner',
                'bio' => 'Runs the studio. Still cuts on Thursdays.',
                'timezone' => 'Europe/Vienna',
                'is_active' => false,
                'sort_order' => 4,
            ],
        );

        return [$maya, $tomas, $priya];
    }

    private function user(Tenant $tenant, string $name, string $email, UserRole $role, string $timezone): User
    {
        return User::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(config('slotflow.demo.password')),
                'role' => $role,
                'timezone' => $timezone,
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, Service>
     */
    private function createServices(Tenant $tenant, Staff $maya, Staff $tomas, Staff $priya): array
    {
        $definitions = [
            'cut' => [
                'name' => 'Cut & finish',
                'description' => 'A consultation, a wash, a cut and a proper finish. Allow a little longer if you are changing the shape.',
                'keywords' => 'haircut, hair cut, cut, trim, blow dry, style, styling, wash and cut',
                'duration' => 45, 'buffer' => 10, 'price' => 4800,
                'color' => '#6366f1', 'staff' => [$maya, $tomas],
            ],
            'colour' => [
                'name' => 'Colour & gloss',
                'description' => 'Full colour with a gloss finish. Includes a strand test if it is your first visit.',
                'keywords' => 'colour, color, dye, highlights, balayage, roots, grey coverage, toner',
                'duration' => 120, 'buffer' => 20, 'price' => 13500,
                'color' => '#ec4899', 'staff' => [$maya], 'deposit' => 3000,
            ],
            'beard' => [
                'name' => 'Beard trim',
                'description' => 'Shape, line up and hot towel. In and out on a lunch break.',
                'keywords' => 'beard, beard trim, shave, barber, moustache, stubble, line up',
                'duration' => 20, 'buffer' => 5, 'price' => 2200,
                'color' => '#f59e0b', 'staff' => [$tomas],
            ],
            'scalp' => [
                'name' => 'Scalp treatment',
                'description' => 'A treatment for dry or irritated scalps, with a short assessment first.',
                'keywords' => 'scalp, dandruff, dry scalp, itchy scalp, flaky, irritation, scalp treatment',
                'duration' => 30, 'buffer' => 10, 'price' => 3800,
                'color' => '#10b981', 'staff' => [$maya, $priya],
            ],
            'consult' => [
                'name' => 'Online hair consultation',
                'description' => 'A video call with our trichologist about hair loss, thinning or scalp conditions.',
                'keywords' => 'consultation, hair loss, thinning, shedding, alopecia, advice, video call, online',
                'duration' => 30, 'buffer' => 0, 'price' => 2500,
                'color' => '#0ea5e9', 'staff' => [$priya],
            ],
            'fringe' => [
                'name' => 'Fringe trim',
                'description' => 'Free for anyone who has had a cut with us in the last six weeks.',
                'keywords' => 'fringe, bangs, fringe trim',
                'duration' => 15, 'buffer' => 0, 'price' => 0,
                'color' => '#a855f7', 'staff' => [$maya, $tomas],
            ],
        ];

        $services = [];
        $order = 0;

        foreach ($definitions as $key => $definition) {
            /** @var Service $service */
            $service = Service::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'keywords' => $definition['keywords'],
                    'duration_minutes' => $definition['duration'],
                    'buffer_minutes' => $definition['buffer'],
                    'price_cents' => $definition['price'],
                    'color' => $definition['color'],
                    'is_active' => true,
                    'requires_deposit' => isset($definition['deposit']),
                    'deposit_cents' => $definition['deposit'] ?? 0,
                    'sort_order' => $order++,
                ],
            );

            $service->staff()->sync(collect($definition['staff'])->pluck('id')->all());
            $services[$key] = $service;
        }

        return $services;
    }

    private function createWorkingHours(Staff $maya, Staff $tomas, Staff $priya): void
    {
        // Maya: Tue–Sat, with a proper lunch break on the long days. Two rules
        // for one day is the normal case, not an edge case.
        $this->hours($maya, [
            [2, '09:00', '13:00'], [2, '14:00', '18:00'],
            [3, '09:00', '13:00'], [3, '14:00', '18:00'],
            [4, '11:00', '19:00'],
            [5, '09:00', '13:00'], [5, '14:00', '18:00'],
            [6, '09:00', '14:00'],
        ]);

        // Tomás: Mon–Fri, straight through.
        $this->hours($tomas, [
            [1, '10:00', '18:00'],
            [2, '10:00', '18:00'],
            [3, '10:00', '18:00'],
            [4, '10:00', '18:00'],
            [5, '10:00', '20:00'],
        ]);

        // Priya: mornings, in Kolkata.
        $this->hours($priya, [
            [1, '09:00', '13:00'],
            [3, '09:00', '13:00'],
            [5, '09:00', '13:00'],
        ]);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $rules
     */
    private function hours(Staff $staff, array $rules): void
    {
        $staff->availabilityRules()->delete();

        foreach ($rules as [$weekday, $start, $end]) {
            AvailabilityRule::query()->create([
                'tenant_id' => $staff->tenant_id,
                'staff_id' => $staff->id,
                'weekday' => $weekday,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }
    }

    private function createTimeOff(Staff $maya): void
    {
        $maya->timeOff()->delete();

        $holidayStart = CarbonImmutable::now('Europe/Vienna')->addDays(12)->startOfDay();

        TimeOff::query()->create([
            'tenant_id' => $maya->tenant_id,
            'staff_id' => $maya->id,
            'starts_at' => $holidayStart->utc(),
            'ends_at' => $holidayStart->addDays(4)->utc(),
            'reason' => 'Holiday',
        ]);

        $dentist = CarbonImmutable::now('Europe/Vienna')->addDays(3)->setTime(14, 0);

        TimeOff::query()->create([
            'tenant_id' => $maya->tenant_id,
            'staff_id' => $maya->id,
            'starts_at' => $dentist->utc(),
            'ends_at' => $dentist->addHours(2)->utc(),
            'reason' => 'Dentist',
        ]);
    }

    /**
     * @return list<Customer>
     */
    private function createCustomers(Tenant $tenant): array
    {
        $customers = [];

        $sam = User::query()->where('tenant_id', $tenant->id)
            ->where('email', config('slotflow.demo.customer_email'))
            ->first();

        // The demo login's own customer record, so signing in as them shows a
        // populated "my bookings" page rather than an empty state.
        $customers[] = Customer::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => config('slotflow.demo.customer_email')],
            [
                'user_id' => $sam?->id,
                'name' => 'Sam Ali',
                'phone' => '+880 17 1234 5678',
                'timezone' => 'Asia/Dhaka',
                'completed_count' => 4,
                'no_show_count' => 0,
                'cancelled_count' => 1,
            ],
        );

        // A believable spread. Most people turn up; a few never do; a couple
        // cannot be reached at all.
        $customers[] = Customer::factory()->loyal()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Hannah Gruber', 'email' => 'hannah@example.test', 'timezone' => 'Europe/Vienna',
        ]);

        $customers[] = Customer::factory()->unreliable()->unreachable()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lukas Wagner', 'email' => 'lukas@example.test', 'timezone' => 'Europe/Vienna',
        ]);

        $customers[] = Customer::factory()->unreliable()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Nina Pavlović', 'email' => 'nina@example.test', 'timezone' => 'Europe/Belgrade',
        ]);

        foreach (Customer::factory()->count(26)->create(['tenant_id' => $tenant->id]) as $customer) {
            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * A year of history, so the risk model and the metrics have something to
     * chew on. Roughly 8% no-shows, which is on the low side of what a real
     * salon sees.
     *
     * @param  array<string, Service>  $services
     * @param  list<Staff>  $team
     * @param  list<Customer>  $customers
     */
    private function createHistory(Tenant $tenant, array $services, array $team, array $customers): void
    {
        $rows = [];
        $now = CarbonImmutable::now('Europe/Vienna');

        // Starts at 0, not 1: whatever time of day the seeder runs, today
        // should already have appointments behind it. A dashboard that is empty
        // every morning because the seed skipped today is a demo that looks
        // broken for no reason.
        for ($daysAgo = 0; $daysAgo <= 180; $daysAgo++) {
            $day = $now->subDays($daysAgo);

            // Closed Sundays; Mondays are quiet.
            if ($day->isSunday()) {
                continue;
            }

            $appointments = $day->isMonday() ? random_int(0, 2) : random_int(2, 6);

            for ($i = 0; $i < $appointments; $i++) {
                $service = $services[array_rand($services)];
                $eligible = $service->staff()->pluck('staff.id')->all();

                if ($eligible === []) {
                    continue;
                }

                $staffId = $eligible[array_rand($eligible)];
                $customer = $customers[array_rand($customers)];

                $start = $day->setTime(random_int(9, 17), [0, 15, 30, 45][random_int(0, 3)]);

                // Today's later slots belong to createUpcoming(), which books
                // them properly and scores them. History only owns the past.
                if ($start->isFuture()) {
                    continue;
                }

                $roll = random_int(1, 100);
                $status = match (true) {
                    $roll <= 8 => BookingStatus::NoShow,
                    $roll <= 18 => BookingStatus::Cancelled,
                    default => BookingStatus::Completed,
                };

                $rows[] = [
                    'tenant_id' => $tenant->id,
                    'reference' => 'BL-'.strtoupper(Str::random(6)),
                    'service_id' => $service->id,
                    'staff_id' => $staffId,
                    'customer_id' => $customer->id,
                    'starts_at' => $start->utc(),
                    'ends_at' => $start->addMinutes($service->duration_minutes)->utc(),
                    'blocks_until' => $start->addMinutes($service->blockingMinutes())->utc(),
                    'status' => $status->value,
                    'source' => [BookingSource::Web, BookingSource::Admin, BookingSource::AiAssistant][random_int(0, 2)]->value,
                    'customer_timezone' => $customer->timezone,
                    'price_cents' => $service->price_cents,
                    'confirmed_at' => $start->subDays(2)->utc(),
                    'completed_at' => $status === BookingStatus::Completed ? $start->utc() : null,
                    'cancelled_at' => $status === BookingStatus::Cancelled ? $start->subDay()->utc() : null,
                    'created_at' => $start->subDays(random_int(1, 21))->utc(),
                    'updated_at' => $start->utc(),
                ];
            }
        }

        // Chunked inserts: 800 individual model saves is 800 round trips and
        // turns a 4-second seed into a 40-second one.
        foreach (array_chunk($rows, 500) as $chunk) {
            Booking::query()->insert($chunk);
        }

        $this->say('Seeded '.count($rows).' historic bookings.');
    }

    /**
     * The next three weeks — what the dashboard and the diary actually show.
     *
     * These go through the model rather than a bulk insert because each one
     * gets a risk assessment, and the assessment is the interesting part.
     *
     * @param  array<string, Service>  $services
     * @param  list<Staff>  $team
     * @param  list<Customer>  $customers
     */
    private function createUpcoming(Tenant $tenant, array $services, array $team, array $customers): void
    {
        $now = CarbonImmutable::now('Europe/Vienna');
        $created = 0;

        for ($dayOffset = 0; $dayOffset <= 21; $dayOffset++) {
            $day = $now->addDays($dayOffset);

            if ($day->isSunday()) {
                continue;
            }

            foreach (range(1, random_int(1, 4)) as $ignored) {
                $service = $services[array_rand($services)];
                $eligible = $service->staff()->pluck('staff.id')->all();

                if ($eligible === []) {
                    continue;
                }

                $staffId = $eligible[array_rand($eligible)];
                $customer = $customers[array_rand($customers)];
                $start = $day->setTime(random_int(9, 17), [0, 15, 30, 45][random_int(0, 3)]);

                if ($start->isPast()) {
                    continue;
                }

                // The diary is contended; overlaps are expected. Skip rather
                // than force, so the seeded data respects the same rule the
                // application enforces.
                $clash = Booking::query()
                    ->where('staff_id', $staffId)
                    ->whereIn('status', BookingStatus::blocking())
                    ->where('starts_at', '<', $start->addMinutes($service->blockingMinutes())->utc())
                    ->where('blocks_until', '>', $start->utc())
                    ->exists();

                if ($clash) {
                    continue;
                }

                $booking = Booking::query()->create([
                    'tenant_id' => $tenant->id,
                    'reference' => 'BL-'.strtoupper(Str::random(6)),
                    'service_id' => $service->id,
                    'staff_id' => $staffId,
                    'customer_id' => $customer->id,
                    'starts_at' => $start->utc(),
                    'ends_at' => $start->addMinutes($service->duration_minutes)->utc(),
                    'blocks_until' => $start->addMinutes($service->blockingMinutes())->utc(),
                    'status' => BookingStatus::Confirmed,
                    'source' => [BookingSource::Web, BookingSource::AiAssistant][random_int(0, 1)],
                    'customer_timezone' => $customer->timezone,
                    'price_cents' => $service->price_cents,
                    'confirmed_at' => CarbonImmutable::now(),
                ]);

                $this->risk->scoreAndStore($booking);
                $created++;
            }
        }

        $created += $this->createFlaggedBookings($tenant, $services, $customers);

        $this->say("Seeded {$created} upcoming bookings, each with a risk assessment.");
    }

    /**
     * Put the two customers with poor attendance into the coming week on
     * purpose.
     *
     * Left to chance, a 180-day seed can easily produce a diary where nobody
     * scores high, and the one screen that exists to show high-risk bookings
     * is empty. That is an honest outcome and a useless demo.
     *
     * @param  array<string, Service>  $services
     * @param  list<Customer>  $customers
     */
    private function createFlaggedBookings(Tenant $tenant, array $services, array $customers): int
    {
        $risky = collect($customers)
            ->filter(fn (Customer $c) => $c->no_show_count >= 2)
            ->take(3);

        $service = $services['cut'];
        $eligible = $service->staff()->pluck('staff.id')->all();

        if ($eligible === [] || $risky->isEmpty()) {
            return 0;
        }

        $created = 0;
        $day = CarbonImmutable::now('Europe/Vienna')->addDays(2);

        foreach ($risky as $index => $customer) {
            // Early slots, booked well ahead — the combination the scorer
            // weights most heavily, on customers who have already missed.
            $start = $day->addDays($index * 2)->setTime(9, 0);

            while ($start->isSunday() || $this->slotTaken($eligible[0], $start, $service)) {
                $start = $start->addDays(1)->setTime(9, 0);
            }

            $booking = Booking::query()->create([
                'tenant_id' => $tenant->id,
                'reference' => 'BL-'.strtoupper(Str::random(6)),
                'service_id' => $service->id,
                'staff_id' => $eligible[0],
                'customer_id' => $customer->id,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes($service->duration_minutes)->utc(),
                'blocks_until' => $start->addMinutes($service->blockingMinutes())->utc(),
                'status' => BookingStatus::Confirmed,
                'source' => BookingSource::Web,
                'customer_timezone' => $customer->timezone,
                'price_cents' => $service->price_cents,
                'confirmed_at' => CarbonImmutable::now()->subDays(24),
                'created_at' => CarbonImmutable::now()->subDays(24),
            ]);

            $this->risk->scoreAndStore($booking);
            $created++;
        }

        return $created;
    }

    private function slotTaken(int $staffId, CarbonImmutable $start, Service $service): bool
    {
        return Booking::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', BookingStatus::blocking())
            ->where('starts_at', '<', $start->addMinutes($service->blockingMinutes())->utc())
            ->where('blocks_until', '>', $start->utc())
            ->exists();
    }

    /**
     * A second workspace with its own everything. Nothing in the demo links to
     * it; it exists so `TenantIsolationTest` is testing against real data
     * rather than an empty table.
     */
    private function createSecondWorkspace(): void
    {
        $other = Tenant::query()->updateOrCreate(
            ['slug' => 'north-dental'],
            [
                'name' => 'North Dental',
                'timezone' => 'Europe/Berlin',
                'currency' => 'EUR',
                'locale' => 'en',
                'contact_email' => 'reception@northdental.test',
                'description' => 'A two-chair dental practice. Present only to prove tenant isolation.',
            ],
        );

        $this->tenants->runFor($other, function () use ($other): void {
            $this->user($other, 'Dr. Anja Roth', 'anja@northdental.test', UserRole::Owner, 'Europe/Berlin');

            $staff = Staff::query()->updateOrCreate(
                ['tenant_id' => $other->id, 'name' => 'Dr. Anja Roth'],
                ['title' => 'Dentist', 'timezone' => 'Europe/Berlin', 'is_active' => true],
            );

            $service = Service::query()->updateOrCreate(
                ['tenant_id' => $other->id, 'slug' => 'check-up'],
                [
                    'name' => 'Check-up',
                    'description' => 'Routine examination and clean.',
                    'duration_minutes' => 30,
                    'buffer_minutes' => 10,
                    'price_cents' => 6000,
                    'is_active' => true,
                ],
            );

            $service->staff()->sync([$staff->id]);

            AvailabilityRule::query()->updateOrCreate(
                ['tenant_id' => $other->id, 'staff_id' => $staff->id, 'weekday' => 2],
                ['starts_at' => '08:00', 'ends_at' => '16:00'],
            );
        });
    }
}
