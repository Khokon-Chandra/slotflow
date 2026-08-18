<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;

interface AiClient
{
    public function run(AiRequest $request): AiResponse;

    /**
     * Identifier recorded on every ai_interactions row.
     *
     * A provider id — "anthropic", "openai", "deepseek", a custom one — or
     * "heuristic" for the built-in fallback.
     */
    public function name(): string;
}
