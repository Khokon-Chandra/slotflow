<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;

/**
 * A deterministic implementation of one AI task.
 *
 * Every task must have one. That rule is what makes this project runnable by
 * anyone who clones it, keeps CI free of network calls and secrets, and means
 * an Anthropic outage degrades the product instead of breaking it.
 */
interface Heuristic
{
    public function handle(AiRequest $request): AiResponse;
}
