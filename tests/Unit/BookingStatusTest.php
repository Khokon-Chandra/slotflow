<?php

declare(strict_types=1);

use App\Enums\BookingStatus;

/**
 * The status state machine. Small, but it is the thing that stops an admin
 * un-cancelling a booking whose slot has already been resold.
 */
it('treats pending, confirmed and completed as occupying the diary', function (): void {
    expect(BookingStatus::Pending->blocksSlot())->toBeTrue();
    expect(BookingStatus::Confirmed->blocksSlot())->toBeTrue();
    expect(BookingStatus::Completed->blocksSlot())->toBeTrue();
});

it('releases the slot once cancelled or missed', function (): void {
    // The row stays — it is the input to risk scoring — but the time is free.
    expect(BookingStatus::Cancelled->blocksSlot())->toBeFalse();
    expect(BookingStatus::NoShow->blocksSlot())->toBeFalse();
});

it('allows a confirmed booking to complete, cancel or be marked a no-show', function (): void {
    expect(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Completed))->toBeTrue();
    expect(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Cancelled))->toBeTrue();
    expect(BookingStatus::Confirmed->canTransitionTo(BookingStatus::NoShow))->toBeTrue();
});

it('refuses to move out of a terminal status', function (BookingStatus $terminal): void {
    expect($terminal->isTerminal())->toBeTrue();
    expect($terminal->allowedTransitions())->toBe([]);

    foreach (BookingStatus::cases() as $target) {
        expect($terminal->canTransitionTo($target))->toBeFalse();
    }
})->with([
    BookingStatus::Completed,
    BookingStatus::Cancelled,
    BookingStatus::NoShow,
]);

it('will not let a cancelled booking become confirmed again', function (): void {
    expect(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Confirmed))->toBeFalse();
});
