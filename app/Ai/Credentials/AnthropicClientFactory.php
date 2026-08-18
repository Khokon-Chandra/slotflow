<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use Anthropic\Client;

/**
 * Builds an Anthropic client for a given key, reusing one per distinct key.
 *
 * Constructing the SDK client runs PSR-18 discovery, so rebuilding it on every
 * call is wasteful — but caching a single client and handing it to every
 * workspace would mean one tenant's requests going out on another's key. The
 * cache is therefore keyed by the key itself, which makes that impossible
 * rather than merely unlikely.
 *
 * Keys are hashed for the map so a memory dump or a var_dump of this object
 * does not spill credentials.
 */
final class AnthropicClientFactory
{
    /** @var array<string, Client> */
    private array $clients = [];

    public function for(string $apiKey): Client
    {
        $handle = hash('xxh128', $apiKey);

        return $this->clients[$handle] ??= new Client(apiKey: $apiKey);
    }

    /**
     * Drop a cached client — used after a key is replaced or removed, so the
     * next call cannot go out on the old credential.
     */
    public function forget(string $apiKey): void
    {
        unset($this->clients[hash('xxh128', $apiKey)]);
    }

    public function flush(): void
    {
        $this->clients = [];
    }
}
