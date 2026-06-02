<?php

declare(strict_types=1);

use App\Domain\Availability\TimeRange;
use Carbon\CarbonImmutable;

/**
 * The interval arithmetic the whole availability engine stands on.
 *
 * Everything here is a pure function, so these run without a container or a
 * database — and they are the tests most likely to catch an off-by-one that
 * would otherwise surface as a double booking.
 */
function span(string $start, string $end): TimeRange
{
    return new TimeRange(
        CarbonImmutable::parse("2026-03-10 {$start}", 'UTC'),
        CarbonImmutable::parse("2026-03-10 {$end}", 'UTC'),
    );
}

it('rejects a range that ends before it starts', function (): void {
    span('11:00', '10:00');
})->throws(InvalidArgumentException::class);

it('rejects a zero-length range', function (): void {
    span('10:00', '10:00');
})->throws(InvalidArgumentException::class);

describe('overlap is half-open', function (): void {
    it('does not consider touching ranges to overlap', function (): void {
        // The whole reason back-to-back appointments are bookable.
        expect(span('09:00', '10:00')->overlaps(span('10:00', '11:00')))->toBeFalse();
        expect(span('10:00', '11:00')->overlaps(span('09:00', '10:00')))->toBeFalse();
    });

    it('detects a genuine overlap in both directions', function (): void {
        expect(span('09:00', '10:30')->overlaps(span('10:00', '11:00')))->toBeTrue();
        expect(span('10:00', '11:00')->overlaps(span('09:00', '10:30')))->toBeTrue();
    });

    it('detects containment', function (): void {
        expect(span('09:00', '17:00')->overlaps(span('12:00', '13:00')))->toBeTrue();
        expect(span('12:00', '13:00')->overlaps(span('09:00', '17:00')))->toBeTrue();
    });
});

describe('subtraction', function (): void {
    it('leaves the range untouched when there is no overlap', function (): void {
        $result = span('09:00', '12:00')->subtract(span('13:00', '14:00'));

        expect($result)->toHaveCount(1);
        expect($result[0]->start->format('H:i'))->toBe('09:00');
        expect($result[0]->end->format('H:i'))->toBe('12:00');
    });

    it('returns nothing when fully covered', function (): void {
        expect(span('10:00', '11:00')->subtract(span('09:00', '12:00')))->toBe([]);
    });

    it('bites off the front', function (): void {
        $result = span('09:00', '12:00')->subtract(span('08:00', '10:00'));

        expect($result)->toHaveCount(1);
        expect($result[0]->start->format('H:i'))->toBe('10:00');
        expect($result[0]->end->format('H:i'))->toBe('12:00');
    });

    it('bites off the end', function (): void {
        $result = span('09:00', '12:00')->subtract(span('11:00', '13:00'));

        expect($result)->toHaveCount(1);
        expect($result[0]->end->format('H:i'))->toBe('11:00');
    });

    it('punches a hole and returns two pieces', function (): void {
        // A lunchtime appointment in the middle of a morning-to-evening shift.
        $result = span('09:00', '17:00')->subtract(span('12:00', '13:00'));

        expect($result)->toHaveCount(2);
        expect($result[0]->start->format('H:i'))->toBe('09:00');
        expect($result[0]->end->format('H:i'))->toBe('12:00');
        expect($result[1]->start->format('H:i'))->toBe('13:00');
        expect($result[1]->end->format('H:i'))->toBe('17:00');
    });
});

describe('subtractAll', function (): void {
    it('removes several blockers in sequence', function (): void {
        $result = TimeRange::subtractAll(
            [span('09:00', '17:00')],
            [span('10:00', '10:30'), span('13:00', '14:00')],
        );

        expect($result)->toHaveCount(3);
        expect(array_map(fn (TimeRange $r) => $r->start->format('H:i').'-'.$r->end->format('H:i'), $result))
            ->toBe(['09:00-10:00', '10:30-13:00', '14:00-17:00']);
    });

    it('can empty a range entirely', function (): void {
        expect(TimeRange::subtractAll([span('09:00', '12:00')], [span('08:00', '18:00')]))->toBe([]);
    });
});

describe('merge', function (): void {
    it('joins overlapping ranges', function (): void {
        $merged = TimeRange::merge([span('09:00', '11:00'), span('10:00', '12:00')]);

        expect($merged)->toHaveCount(1);
        expect($merged[0]->end->format('H:i'))->toBe('12:00');
    });

    it('joins touching ranges', function (): void {
        $merged = TimeRange::merge([span('09:00', '10:00'), span('10:00', '11:00')]);

        expect($merged)->toHaveCount(1);
    });

    it('keeps genuinely separate ranges apart and sorts them', function (): void {
        $merged = TimeRange::merge([span('14:00', '18:00'), span('09:00', '13:00')]);

        expect($merged)->toHaveCount(2);
        expect($merged[0]->start->format('H:i'))->toBe('09:00');
    });

    it('does not swallow a shorter range inside a longer one', function (): void {
        $merged = TimeRange::merge([span('09:00', '17:00'), span('10:00', '11:00')]);

        expect($merged)->toHaveCount(1);
        expect($merged[0]->end->format('H:i'))->toBe('17:00');
    });
});

it('reports duration in minutes', function (): void {
    expect(span('09:00', '10:30')->durationMinutes())->toBe(90);
});
