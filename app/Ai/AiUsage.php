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
        // False when the model's rates are unknown: cost is untracked, not
        // zero. The two look identical in a sum and mean opposite things.
        public bool $tracked = true,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Price the call from the rates that came with the credential.
     *
     * `$rates` is null when nobody has told this application what the model
     * costs — most providers, until a workspace enters its own figures. The
     * call still happens and the token counts are still recorded; only the
     * cost is unknown, and `tracked` says so rather than reporting zero.
     *
     * A budget you cannot measure is not a budget, and a zero that means
     * "unknown" is how a spend ceiling silently stops working.
     *
     * @param  array{input: float, output: float}|null  $rates
     */
    public static function priced(
        ?array $rates,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
    ): self {
        if ($rates === null) {
            return new self($inputTokens, $outputTokens, $cachedInputTokens, costMicros: 0, tracked: false);
        }

        // Rates are USD per million tokens; 1 USD = 1_000_000 micros. The two
        // millions cancel, so micros = tokens * rate.
        $costMicros = (int) round(
            $inputTokens * $rates['input'] + $outputTokens * $rates['output']
        );

        return new self($inputTokens, $outputTokens, $cachedInputTokens, $costMicros, tracked: true);
    }

    public function costUsd(): float
    {
        return $this->costMicros / 1_000_000;
    }
}
