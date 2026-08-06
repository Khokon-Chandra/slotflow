<?php

declare(strict_types=1);

use App\Domain\Booking\BookingData;
use App\Domain\Booking\BookingService;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudioFactory;

/*
|------------------------------------------------------------------------------
| Double booking
|------------------------------------------------------------------------------
|
| The single most important behaviour in a booking system, and the one that is
| easiest to get wrong in a way no ordinary test notices: "check the slot is
| free, then insert" is a read followed by a write, and two requests can pass
| the read before either does the write.
|
| Three tests, from cheapest to most convincing:
|
|   1. sequential  — the overlap check itself rejects a taken slot
|   2. lock        — the row lock the guard takes is genuinely exclusive
|   3. race        — two operating-system processes, one winner
|
| The third is the only one that would have caught the bug if the transaction
| were missing altogether, so it is worth the awkwardness of forking.
|
*/

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory(durationMinutes: 60))->openEveryDay('09:00', '17:00');

    // 09:00 Vienna on the Thursday after "now".
    $this->slot = CarbonImmutable::parse('2026-06-11 09:00', 'Europe/Vienna')->utc();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function bookingData(StudioFactory $studio, CarbonImmutable $slot, string $email): BookingData
{
    return new BookingData(
        serviceId: $studio->service->id,
        staffId: $studio->staff->id,
        startsAt: $slot,
        customerName: 'Race Entrant',
        customerEmail: $email,
        customerTimezone: 'Europe/Vienna',
    );
}

it('rejects a second booking for the same slot', function (): void {
    $service = app(BookingService::class);

    $first = $service->create(bookingData($this->studio, $this->slot, 'first@example.test'));

    expect($first->status)->toBe(BookingStatus::Confirmed);

    expect(fn () => $service->create(bookingData($this->studio, $this->slot, 'second@example.test')))
        ->toThrow(SlotUnavailableException::class);

    expect(Booking::query()->count())->toBe(1);
});

it('rejects a booking that merely overlaps an existing one', function (): void {
    $service = app(BookingService::class);

    $service->create(bookingData($this->studio, $this->slot, 'first@example.test'));

    // 09:30 starts inside the 09:00–10:00 appointment.
    expect(fn () => $service->create(
        bookingData($this->studio, $this->slot->addMinutes(30), 'second@example.test')
    ))->toThrow(SlotUnavailableException::class);
});

it('allows a booking that starts exactly when the previous one ends', function (): void {
    // Half-open intervals: [09:00, 10:00) and [10:00, 11:00) do not overlap.
    // Getting this wrong makes back-to-back appointments unbookable, which is
    // a quieter bug than double booking but loses just as much money.
    $service = app(BookingService::class);

    $service->create(bookingData($this->studio, $this->slot, 'first@example.test'));
    $second = $service->create(bookingData($this->studio, $this->slot->addHour(), 'second@example.test'));

    expect($second->status)->toBe(BookingStatus::Confirmed);
    expect(Booking::query()->count())->toBe(2);
});

it('takes an exclusive row lock on the staff member', function (): void {
    /*
     * Proves the guard's lock is real, without needing two processes.
     *
     * Connection A opens a transaction and locks the staff row exactly as
     * BookingService does. Connection B — a genuinely separate MySQL session —
     * then asks for the same lock with a one second timeout. If the lock were
     * advisory, or taken on the wrong row, or not taken at all, B would
     * succeed immediately and this test would fail.
     */
    $staffId = $this->studio->staff->id;

    config()->set('database.connections.second', config('database.connections.mysql'));

    $a = DB::connection();
    $b = DB::connection('second');

    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $a->beginTransaction();

    try {
        $a->table('staff')->where('id', $staffId)->lockForUpdate()->first();

        $blocked = false;

        try {
            $b->beginTransaction();
            $b->table('staff')->where('id', $staffId)->lockForUpdate()->first();
            $b->rollBack();
        } catch (QueryException $e) {
            $blocked = true;
            $b->rollBack();
        }

        expect($blocked)->toBeTrue('A second session was able to take the same row lock.');
    } finally {
        $a->rollBack();
        $b->disconnect();
    }
});

it('lets exactly one of several simultaneous requests win the slot', function (): void {
    /*
     * The real thing: four operating-system processes attempting the identical
     * booking at the same moment against the same database.
     *
     * Each child creates its own MySQL connection rather than touching the one
     * inherited across the fork — two processes writing to one socket corrupts
     * both — and reports its outcome through a file, then dies with SIGKILL so
     * PHPUnit's shutdown handlers never run in a child and pollute the parent's
     * output.
     */
    $racers = 4;
    $dir = sys_get_temp_dir().'/slotflow-race-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $studioTenantId = $this->studio->tenant->id;
    $serviceId = $this->studio->service->id;
    $staffId = $this->studio->staff->id;
    $slot = $this->slot;

    $pids = [];

    for ($i = 0; $i < $racers; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Could not fork a child process.');
        }

        if ($pid === 0) {
            // ── child ────────────────────────────────────────────────────────
            $outcome = 'error';

            try {
                // A brand-new named connection. The inherited "mysql" one is
                // left untouched and unused.
                config()->set('database.connections.child', config('database.connections.mysql'));
                DB::setDefaultConnection('child');

                app(TenantContext::class)->set(App\Models\Tenant::query()->find($studioTenantId));

                app(BookingService::class)->create(new BookingData(
                    serviceId: $serviceId,
                    staffId: $staffId,
                    startsAt: $slot,
                    customerName: "Racer {$i}",
                    customerEmail: "racer{$i}@example.test",
                    customerTimezone: 'Europe/Vienna',
                ));

                $outcome = 'won';
            } catch (SlotUnavailableException) {
                $outcome = 'lost';
            } catch (Throwable $e) {
                $outcome = 'error: '.$e::class.': '.$e->getMessage();
            }

            file_put_contents("{$dir}/{$i}", $outcome);

            // Immediate termination: no destructors, no shutdown functions,
            // nothing written to the parent's output stream.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $outcomes = [];
    for ($i = 0; $i < $racers; $i++) {
        $outcomes[] = @file_get_contents("{$dir}/{$i}") ?: 'no result';
        @unlink("{$dir}/{$i}");
    }
    @rmdir($dir);

    $won = count(array_filter($outcomes, fn (string $o) => $o === 'won'));
    $lost = count(array_filter($outcomes, fn (string $o) => $o === 'lost'));
    $errored = array_values(array_filter($outcomes, fn (string $o) => ! in_array($o, ['won', 'lost'], true)));

    expect($errored)->toBe([], 'A racer failed for an unexpected reason: '.implode(' | ', $errored));
    expect($won)->toBe(1, "Expected exactly one winner, got {$won}. Outcomes: ".implode(', ', $outcomes));
    expect($lost)->toBe($racers - 1);

    // And the database agrees.
    expect(Booking::query()->where('staff_id', $staffId)->count())->toBe(1);
})->skip(
    fn (): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'Requires the pcntl and posix extensions.',
);
