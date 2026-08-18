<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use InvalidArgumentException;

/**
 * One entry from the provider catalogue in config/ai.php.
 *
 * A value object rather than an enum because the catalogue is configuration:
 * adding a provider that speaks a shape a driver already understands should be
 * an entry in a file, not a new case, a new match arm and a migration.
 */
final readonly class Provider
{
    /**
     * @param  array<string, array{label?: string, input: float|null, output: float|null}>  $models
     */
    private function __construct(
        public string $id,
        public string $label,
        public string $driver,
        public ?string $baseUrl,
        public string $keyHint,
        public ?string $consoleUrl,
        public ?string $pricingUrl,
        public bool $supportsJsonSchema,
        public bool $requiresBaseUrl,
        public array $models,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $id, array $config): self
    {
        foreach (['label', 'driver'] as $required) {
            if (! isset($config[$required])) {
                throw new InvalidArgumentException("Provider [{$id}] is missing its {$required}.");
            }
        }

        return new self(
            id: $id,
            label: (string) $config['label'],
            driver: (string) $config['driver'],
            baseUrl: $config['base_url'] ?? null,
            keyHint: (string) ($config['key_hint'] ?? ''),
            consoleUrl: $config['console_url'] ?? null,
            pricingUrl: $config['pricing_url'] ?? null,
            supportsJsonSchema: (bool) ($config['supports_json_schema'] ?? false),
            requiresBaseUrl: (bool) ($config['requires_base_url'] ?? false),
            models: $config['models'] ?? [],
        );
    }

    public function isCustom(): bool
    {
        return $this->requiresBaseUrl;
    }

    public function hasModel(string $model): bool
    {
        return array_key_exists($model, $this->models);
    }

    public function defaultModel(): ?string
    {
        return array_key_first($this->models);
    }

    /**
     * Published rates for a model, in USD per million tokens.
     *
     * Null means nobody has told this application what the model costs — see
     * the note in config/ai.php. A workspace supplies its own rates and spend
     * tracking starts working; until then, cost is reported as untracked
     * rather than as zero.
     *
     * @return array{input: float, output: float}|null
     */
    public function rates(string $model): ?array
    {
        $rates = $this->models[$model] ?? null;

        if ($rates === null || $rates['input'] === null || $rates['output'] === null) {
            return null;
        }

        return ['input' => (float) $rates['input'], 'output' => (float) $rates['output']];
    }

    /**
     * @return list<array{id: string, label: string, input_per_mtok_usd: float|null, output_per_mtok_usd: float|null, has_rates: bool}>
     */
    public function modelOptions(): array
    {
        $options = [];

        foreach ($this->models as $id => $model) {
            $options[] = [
                'id' => (string) $id,
                'label' => isset($model['label']) ? (string) $model['label'] : (string) $id,
                'input_per_mtok_usd' => $model['input'],
                'output_per_mtok_usd' => $model['output'],
                'has_rates' => $model['input'] !== null && $model['output'] !== null,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'driver' => $this->driver,
            'base_url' => $this->baseUrl,
            'key_hint' => $this->keyHint,
            'console_url' => $this->consoleUrl,
            'pricing_url' => $this->pricingUrl,
            'supports_json_schema' => $this->supportsJsonSchema,
            'requires_base_url' => $this->requiresBaseUrl,
            'models' => $this->modelOptions(),
        ];
    }
}
