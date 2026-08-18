<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use RuntimeException;

/**
 * The catalogue of connectable providers, read from config/ai.php.
 *
 * Everything that needs to know what a provider is — the settings page, the
 * validation rules, the drivers, the cost calculator — asks this, so the
 * answer cannot drift between them.
 */
final class ProviderRegistry
{
    /** @var array<string, Provider>|null */
    private ?array $providers = null;

    /**
     * @return array<string, Provider>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('ai.providers', []);

        $providers = [];

        foreach ($configured as $id => $config) {
            $providers[(string) $id] = Provider::fromConfig((string) $id, $config);
        }

        return $this->providers = $providers;
    }

    public function find(string $id): ?Provider
    {
        return $this->all()[$id] ?? null;
    }

    public function require(string $id): Provider
    {
        return $this->find($id) ?? throw new RuntimeException("Unknown AI provider [{$id}].");
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Providers a workspace can pick from a list. Excludes the custom entry,
     * which is a shape rather than a service and needs its own form.
     *
     * @return list<Provider>
     */
    public function connectable(): array
    {
        return array_values(array_filter($this->all(), fn (Provider $p) => ! $p->isCustom()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(fn (Provider $p) => $p->toArray(), $this->all()));
    }
}
