<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|------------------------------------------------------------------------------
| Test case bindings
|------------------------------------------------------------------------------
|
| tests/Unit holds pure tests over value objects and enums — no container, no
| database, no HTTP. They run in microseconds and are deliberately left out of
| RefreshDatabase.
|
| tests/Feature covers everything that touches the database or the HTTP layer.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|------------------------------------------------------------------------------
| Helpers
|------------------------------------------------------------------------------
*/

/**
 * The UTC instant for a wall-clock expression in a named zone.
 *
 * Written out because every availability assertion needs it, and inlining
 * `CarbonImmutable::parse($s, $tz)->utc()` buries the thing being tested.
 */
function utcFrom(string $expression, string $timezone = 'Europe/Vienna'): CarbonImmutable
{
    return CarbonImmutable::parse($expression, $timezone)->utc();
}
