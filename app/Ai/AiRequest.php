<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiTask;

/**
 * One unit of work for the AI layer.
 *
 * It carries two representations of the same job on purpose:
 *
 *   $system / $prompt  — the rendered text a language model reads
 *   $payload           — the structured inputs, for the heuristic driver
 *
 * That is what lets the fallback driver be a real implementation rather than
 * an apology. A heuristic cannot do anything useful with a paragraph of
 * English, but it can do plenty with `['text' => …, 'services' => […]]`.
 */
final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>|null  $schema  JSON schema for structured output
     * @param  array<string, mixed>  $payload  structured inputs for the heuristic driver
     */
    public function __construct(
        public AiTask $task,
        public string $system,
        public string $prompt,
        public ?array $schema = null,
        public array $payload = [],
        public ?string $cacheKey = null,
        public ?int $maxTokens = null,
        public ?string $effort = null,
    ) {}

    public function expectsJson(): bool
    {
        return $this->schema !== null;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens ?? (int) config('ai.claude.max_tokens', 2000);
    }

    public function effort(): string
    {
        return $this->effort ?? (string) config('ai.claude.effort', 'low');
    }

    /**
     * Cache identity. Falls back to a hash of the actual prompt, so two calls
     * that would produce the same answer share one.
     */
    public function cacheKey(): string
    {
        $suffix = $this->cacheKey ?? substr(hash('xxh128', $this->system.'|'.$this->prompt), 0, 16);

        return "ai:{$this->task->value}:{$suffix}";
    }
}
