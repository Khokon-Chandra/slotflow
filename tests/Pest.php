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
| tests/Concurrency truncates instead of transacting.
|
| RefreshDatabase wraps each test in a transaction and rolls it back, which is
| fast and perfectly correct — but it means nothing is ever committed, and a
| second connection or a forked process cannot see the fixtures. The one test
| that matters most in this project needs real committed rows and real
| concurrent connections, so it pays the cost of truncation.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|------------------------------------------------------------------------------
| Expectations
|------------------------------------------------------------------------------
*/

/**
 * Assert a response carries this project's error envelope with a given
 * machine-readable code — the part of the API contract clients branch on.
 */
expect()->extend('toHaveErrorCode', function (string $code) {
    /** @var Illuminate\Testing\TestResponse $response */
    $response = $this->value;
    $payload = $response->json();

    expect($payload)->toHaveKey('error.code');
    expect($payload['error']['code'])->toBe($code);

    return $this;
});

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
