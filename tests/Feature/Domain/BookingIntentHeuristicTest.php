<?php

declare(strict_types=1);

use App\Ai\Assistants\BookingAssistant;
use App\Ai\Tasks\ParseBookingRequest;
use Carbon\CarbonImmutable;
use Tests\Support\StudioFactory;

/**
 * The offline booking parser.
 *
 * AI_DRIVER is "heuristic" throughout the suite, so these tests exercise the
 * deterministic fallback — which is the point. If this parser stops working,
 * the demo stops working for anyone without an API key, and the safety net
 * under the Claude driver is gone.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));  // Wednesday

    $this->studio = (new StudioFactory(durationMinutes: 45))->openEveryDay('09:00', '18:00');

    $this->studio->service->update([
        'name' => 'Cut & finish',
        'slug' => 'cut-finish',
        'keywords' => 'haircut, hair cut, cut, trim, blow dry',
    ]);

    $this->parse = fn (string $text) => (app(ParseBookingRequest::class))(
        $this->studio->tenant,
        $text,
        'Europe/Vienna',
    )['intent'];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('matches a service by an owner-configured keyword', function (): void {
    // "haircut" appears nowhere in "Cut & finish". Only the keyword list
    // bridges the two, which is exactly why the column exists.
    $intent = ($this->parse)('I need a haircut');

    expect($intent->serviceId)->toBe($this->studio->service->id);
    expect($intent->confidence)->toBe('high');
});

it('declines to guess when nothing matches', function (): void {
    $intent = ($this->parse)('do you do manicures?');

    expect($intent->serviceId)->toBeNull();
    expect($intent->clarification)->not->toBeNull();
});

describe('dates', function (): void {
    it('understands today and tomorrow', function (): void {
        expect(($this->parse)('haircut today')->dateFrom->toDateString())->toBe('2026-06-10');
        expect(($this->parse)('haircut tomorrow')->dateFrom->toDateString())->toBe('2026-06-11');
    });

    it('resolves a bare weekday to the next one', function (): void {
        // Wednesday 10 June → Friday 12 June.
        expect(($this->parse)('haircut on friday')->dateFrom->toDateString())->toBe('2026-06-12');
    });

    it('searches both readings of "next <weekday>"', function (): void {
        // English is genuinely ambiguous here, so the window covers both
        // rather than picking one and being wrong half the time.
        $intent = ($this->parse)('haircut next tuesday');

        expect($intent->dateFrom->toDateString())->toBe('2026-06-16');
        expect($intent->dateUntil->toDateString())->toBe('2026-06-23');
    });

    it('accepts an explicit ISO date', function (): void {
        expect(($this->parse)('haircut on 2026-07-04')->dateFrom->toDateString())->toBe('2026-07-04');
    });

    it('falls back to the coming week when no date is given', function (): void {
        $intent = ($this->parse)('haircut please');

        expect($intent->dateFrom->toDateString())->toBe('2026-06-10');
        expect($intent->dateUntil->toDateString())->toBe('2026-06-17');
    });

    it('treats "asap" as the next few days', function (): void {
        $intent = ($this->parse)('haircut asap');

        expect($intent->dateFrom->toDateString())->toBe('2026-06-10');
        expect($intent->dateUntil->diffInDays($intent->dateFrom))->toBeLessThanOrEqual(7);
    });
});

describe('time of day', function (): void {
    it('reads morning, afternoon and evening', function (string $text, string $expected): void {
        expect(($this->parse)("haircut tomorrow {$text}")->timeOfDay)->toBe($expected);
    })->with([
        ['in the morning', 'morning'],
        ['in the afternoon', 'afternoon'],
        ['in the evening', 'evening'],
        ['after work', 'evening'],
        ['first thing', 'morning'],
    ]);

    it('reads an explicit clock time', function (): void {
        $intent = ($this->parse)('haircut tomorrow at 3pm');

        expect($intent->timeOfDay)->toBe('afternoon');
        expect($intent->earliestTime)->toBe('15:00');
    });

    it('assumes the afternoon for a bare low hour', function (): void {
        // Nobody means five in the morning when they say "at 5".
        expect(($this->parse)('haircut tomorrow at 5')->earliestTime)->toBe('17:00');
    });

    it('leaves the window open when no time is mentioned', function (): void {
        $intent = ($this->parse)('haircut tomorrow');

        expect($intent->timeOfDay)->toBe('any');
        expect($intent->earliestTime)->toBeNull();
    });
});

it('picks up a named team member', function (): void {
    $this->studio->staff->update(['name' => 'Maya Brenner']);

    expect(($this->parse)('can Maya cut my hair on friday')->staffId)->toBe($this->studio->staff->id);
});

describe('the assistant end to end', function (): void {
    it('turns a sentence into slots that are genuinely free', function (): void {
        $result = app(BookingAssistant::class)->suggest(
            $this->studio->tenant,
            'a haircut tomorrow morning',
            'Europe/Vienna',
        );

        expect($result['service']['name'])->toBe('Cut & finish');
        expect($result['slots'])->not->toBeEmpty();

        foreach ($result['slots'] as $slot) {
            expect($slot['local_date'])->toBe('2026-06-11');
            expect((int) substr($slot['local_time'], 0, 2))->toBeLessThan(12);
        }

        // The response says which driver answered, so the UI can label it.
        expect($result['ai']['driver'])->toBe('heuristic');
    });

    it('widens the search rather than showing an empty page', function (): void {
        // The studio opens at 09:00, so an evening request has nothing in it.
        $result = app(BookingAssistant::class)->suggest(
            $this->studio->tenant,
            'a haircut tomorrow at 10pm',
            'Europe/Vienna',
        );

        expect($result['relaxed'])->toBeTrue();
        expect($result['slots'])->not->toBeEmpty();
        expect($result['message'])->toContain('nearby');
    });

    it('asks a question instead of guessing a service', function (): void {
        $result = app(BookingAssistant::class)->suggest(
            $this->studio->tenant,
            'something nice',
            'Europe/Vienna',
        );

        expect($result['service'])->toBeNull();
        expect($result['slots'])->toBe([]);
        expect($result['message'])->not->toBeEmpty();
    });

    it('never writes a booking', function (): void {
        app(BookingAssistant::class)->suggest($this->studio->tenant, 'haircut tomorrow', 'Europe/Vienna');

        // The model narrows a search. It does not make appointments.
        expect(App\Models\Booking::query()->count())->toBe(0);
    });
});

it('records every call for later inspection', function (): void {
    ($this->parse)('haircut tomorrow');

    $interaction = App\Models\AiInteraction::query()->latest('id')->first();

    expect($interaction)->not->toBeNull();
    expect($interaction->task->value)->toBe('booking_intent');
    expect($interaction->driver)->toBe('heuristic');
    expect($interaction->cost_micros)->toBe(0);
});
