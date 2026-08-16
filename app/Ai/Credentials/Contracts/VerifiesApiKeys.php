<?php

declare(strict_types=1);

namespace App\Ai\Credentials\Contracts;

use App\Ai\Credentials\KeyVerification;

/**
 * Checks that an API key works, before anything is stored under it.
 *
 * An interface rather than a concrete call so the test suite can substitute a
 * verifier — same reason `AiClient` is an interface. Without it, every test
 * that saves a key would need a live credential and a network call, which is
 * exactly what this project refuses to depend on.
 */
interface VerifiesApiKeys
{
    public function __invoke(string $apiKey, string $model): KeyVerification;
}
