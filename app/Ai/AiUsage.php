<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Token counts and the cost they imply, for one call.
 *
 * Cost is carried in micro-dollars as an integer so a month of rows can be
 * summed without floating-point drift.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $costMicros = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Price the call from the per-model rates in config/ai.php.
     */
    public static function priced(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
    ): self {
        /** @var array<string, array{input: float, output: float}> $pricing */
        $pricing = config('ai.pricing', []);
        $rates = $pricing[$model] ?? ['input' => 0.0, 'output' => 0.0];

        // Rates are USD per million tokens; 1 USD = 1_000_000 micros. The two
        // millions cancel, so micros = tokens * rate.
        $costMicros = (int) round(
            $inputTokens * $rates['input'] + $outputTokens * $rates['output']
        );

        return new self($inputTokens, $outputTokens, $cachedInputTokens, $costMicros);
    }

    public function costUsd(): float
    {
        return $this->costMicros / 1_000_000;
    }
}
