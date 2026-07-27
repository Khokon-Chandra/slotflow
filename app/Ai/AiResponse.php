<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The result of one AI call, whoever produced it.
 *
 * `driver` and `model` travel all the way to the UI. A user should always be
 * able to see whether they are reading something a model wrote or something a
 * template did — presenting the two identically is how "AI features" lose
 * people's trust.
 */
final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $data  decoded structured output ([] for plain text)
     */
    public function __construct(
        public array $data,
        public string $text,
        public string $driver,
        public ?string $model = null,
        public AiUsage $usage = new AiUsage,
        public int $latencyMs = 0,
        public bool $fromCache = false,
        public ?string $degradedReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function heuristic(array $data, string $text = '', ?string $degradedReason = null): self
    {
        return new self(
            data: $data,
            text: $text,
            driver: 'heuristic',
            degradedReason: $degradedReason,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function isModelWritten(): bool
    {
        return $this->driver === 'claude';
    }

    /**
     * What the UI shows next to an AI-generated block.
     *
     * @return array{driver: string, model: string|null, cached: bool, degraded_reason: string|null}
     */
    public function provenance(): array
    {
        return [
            'driver' => $this->driver,
            'model' => $this->model,
            'cached' => $this->fromCache,
            'degraded_reason' => $this->degradedReason,
        ];
    }

    public function withCacheFlag(bool $fromCache): self
    {
        return new self(
            data: $this->data,
            text: $this->text,
            driver: $this->driver,
            model: $this->model,
            usage: $this->usage,
            latencyMs: $this->latencyMs,
            fromCache: $fromCache,
            degradedReason: $this->degradedReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'text' => $this->text,
            'driver' => $this->driver,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'from_cache' => $this->fromCache,
            'degraded_reason' => $this->degradedReason,
            'usage' => [
                'input_tokens' => $this->usage->inputTokens,
                'output_tokens' => $this->usage->outputTokens,
                'cached_input_tokens' => $this->usage->cachedInputTokens,
                'cost_micros' => $this->usage->costMicros,
            ],
        ];
    }
}
