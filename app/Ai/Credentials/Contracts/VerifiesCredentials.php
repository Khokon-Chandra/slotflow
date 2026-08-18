<?php

declare(strict_types=1);

namespace App\Ai\Credentials\Contracts;

use App\Ai\Credentials\KeyVerification;
use App\Ai\Providers\Provider;

/**
 * Checks that a credential works, before anything is stored under it.
 *
 * An interface rather than a concrete call so the test suite can substitute a
 * verifier — same reason `AiClient` is an interface. Without it, every test
 * that saves a key would need a live credential and a network call, which is
 * exactly what this project refuses to depend on.
 */
interface VerifiesCredentials
{
    public function __invoke(
        Provider $provider,
        string $apiKey,
        string $model,
        ?string $baseUrl = null,
    ): KeyVerification;
}
