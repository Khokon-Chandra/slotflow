<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;

interface AiClient
{
    public function run(AiRequest $request): AiResponse;

    /**
     * Identifier recorded on every ai_interactions row: "claude", "heuristic".
     */
    public function name(): string;
}
