<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Contracts\Heuristic;
use App\Ai\Heuristics;
use App\Enums\AiTask;
use Illuminate\Contracts\Container\Container;

/**
 * The driver that never makes a network call.
 *
 * It is not a stub. Each task has a real deterministic implementation, good
 * enough that the demo is genuinely usable with no API key — which is the
 * point: a portfolio project nobody can run is a screenshot.
 *
 * It is also the safety net. If Anthropic is slow, the key is missing, the
 * monthly budget is spent or the response fails to parse, AiManager routes
 * here and the user gets a plainer answer instead of a stack trace.
 */
final class HeuristicClient implements AiClient
{
    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'heuristic';
    }

    public function run(AiRequest $request): AiResponse
    {
        // An exhaustive match rather than a lookup table: adding a task to
        // AiTask without a fallback implementation then fails to compile
        // rather than failing at runtime, in production, on the one request
        // where the API was down.
        $handler = match ($request->task) {
            AiTask::BookingIntent => Heuristics\BookingIntentHeuristic::class,
            AiTask::NoShowRationale => Heuristics\RiskNarrativeHeuristic::class,
            AiTask::DailyBriefing => Heuristics\DailyBriefingHeuristic::class,
            AiTask::ServiceCopy => Heuristics\ServiceCopyHeuristic::class,
        };

        /** @var Heuristic $heuristic */
        $heuristic = $this->container->make($handler);

        return $heuristic->handle($request);
    }
}
