<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\AiClient;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Drivers\ClaudeClient;
use App\Ai\Drivers\HeuristicClient;
use App\Models\AiInteraction;
use App\Support\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The only class the rest of the application talks to about AI.
 *
 * Everything that makes an LLM feature safe to ship in a product someone pays
 * for lives here, in one readable order:
 *
 *   cache → rate limit → budget → call → fall back → log
 *
 * A controller calling the Anthropic SDK directly would work on the happy
 * path and then, three weeks in, produce a €400 bill, a 30-second page load,
 * and no way to answer "why did it say that?". None of these six steps is
 * clever; leaving any of them out is what turns a demo into an incident.
 */
final class AiManager implements AiClient
{
    public function __construct(
        private readonly Container $container,
        private readonly TenantContext $tenants,
        private readonly AiCredentials $credentials,
    ) {}

    public function name(): string
    {
        return $this->shouldUseClaude() ? 'claude' : 'heuristic';
    }

    /**
     * True when a real API key is configured and the driver allows Claude.
     * Surfaced in the UI so the demo can say which mode it is running in.
     */
    public function isLive(): bool
    {
        return $this->shouldUseClaude();
    }

    public function run(AiRequest $request): AiResponse
    {
        $cached = $this->fromCache($request);

        if ($cached !== null) {
            $this->record($request, $cached, servedFromCache: true);

            return $cached;
        }

        $response = $this->execute($request);

        if ($response->driver === 'claude') {
            $this->store($request, $response);
        }

        $this->record($request, $response, servedFromCache: false);

        return $response;
    }

    private function execute(AiRequest $request): AiResponse
    {
        if (! $this->shouldUseClaude()) {
            return $this->heuristic($request, $this->offlineReason());
        }

        if (! $this->withinRateLimit($request)) {
            return $this->heuristic($request, 'rate_limited');
        }

        if (! $this->withinBudget()) {
            return $this->heuristic($request, 'monthly_budget_reached');
        }

        try {
            return $this->container->make(ClaudeClient::class)->run($request);
        } catch (Throwable $e) {
            // A failed AI call must never fail the request that triggered it.
            // The user gets the plainer answer; the operator gets the stack
            // trace in the log and a `succeeded = false` row in the audit table.
            Log::warning('AI call failed; falling back to the heuristic driver.', [
                'task' => $request->task->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->heuristic($request, 'api_error')
                ->withCacheFlag(false);
        }
    }

    private function heuristic(AiRequest $request, ?string $reason): AiResponse
    {
        $startedAt = hrtime(true);

        /** @var HeuristicClient $client */
        $client = $this->container->make(HeuristicClient::class);
        $response = $client->run($request);

        return new AiResponse(
            data: $response->data,
            text: $response->text,
            driver: 'heuristic',
            model: null,
            usage: AiUsage::none(),
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            fromCache: false,
            degradedReason: $reason,
        );
    }

    private function shouldUseClaude(): bool
    {
        $driver = (string) config('ai.driver', 'auto');

        return match ($driver) {
            'claude' => true,
            'heuristic' => false,
            // The key may be the workspace's own or the platform's — see
            // App\Ai\Credentials\AiCredentials.
            default => $this->credentials->hasKey(),
        };
    }

    /**
     * Where the credential in use came from: "tenant", "platform" or "none".
     * Surfaced in the admin panel so an owner can tell who is paying.
     */
    public function keySource(): string
    {
        return $this->credentials->source();
    }

    private function offlineReason(): ?string
    {
        return config('ai.driver') === 'heuristic'
            ? null                       // deliberate configuration, not a degradation
            : 'no_api_key';
    }

    /**
     * Per-tenant, per-task ceiling. Protects the API budget from a single
     * enthusiastic customer holding down the "suggest" button.
     */
    private function withinRateLimit(AiRequest $request): bool
    {
        $key = sprintf('ai:%s:%s', $request->task->value, $this->tenants->id() ?? 'global');

        return RateLimiter::attempt(
            key: $key,
            maxAttempts: $request->task->rateLimitPerMinute(),
            callback: fn () => true,
            decaySeconds: 60,
        ) !== false;
    }

    /**
     * A soft monthly ceiling. Crossing it degrades the product rather than
     * breaking it — which is the right failure mode for a spend limit, and
     * the difference between a bad morning and a bad quarter.
     */
    private function withinBudget(): bool
    {
        $budgetUsd = $this->credentials->monthlyBudgetUsd();

        if ($budgetUsd <= 0) {
            return true;
        }

        // Scoped to the workspace, because the ceiling is now per workspace.
        // A shared counter would let a busy tenant spend a quiet one's budget.
        $tenantId = $this->tenants->id();

        $spentMicros = Cache::remember(
            sprintf('ai:spend:%s:%s', $tenantId ?? 'platform', now()->format('Y-m')),
            60,
            fn (): int => (int) AiInteraction::query()
                ->withoutTenantScope()
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->thisMonth()
                ->billable()
                ->sum('cost_micros'),
        );

        return $spentMicros < $budgetUsd * 1_000_000;
    }

    private function fromCache(AiRequest $request): ?AiResponse
    {
        $ttl = (int) config('ai.cache_ttl', 900);

        if ($ttl <= 0) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get($request->cacheKey());

        if ($payload === null) {
            return null;
        }

        return new AiResponse(
            data: $payload['data'],
            text: $payload['text'],
            driver: $payload['driver'],
            model: $payload['model'],
            usage: AiUsage::none(),      // a cache hit costs nothing
            latencyMs: 0,
            fromCache: true,
        );
    }

    private function store(AiRequest $request, AiResponse $response): void
    {
        $ttl = (int) config('ai.cache_ttl', 900);

        if ($ttl <= 0) {
            return;
        }

        Cache::put($request->cacheKey(), [
            'data' => $response->data,
            'text' => $response->text,
            'driver' => $response->driver,
            'model' => $response->model,
        ], $ttl);
    }

    /**
     * Write the audit row. Wrapped because observability must never be the
     * thing that takes a request down.
     */
    private function record(AiRequest $request, AiResponse $response, bool $servedFromCache): void
    {
        try {
            AiInteraction::create([
                'tenant_id' => $this->tenants->id(),
                'user_id' => Auth::id(),
                'task' => $request->task,
                'driver' => $response->driver,
                'model' => $response->model,
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
                'cached_input_tokens' => $response->usage->cachedInputTokens,
                'cost_micros' => $response->usage->costMicros,
                'latency_ms' => $response->latencyMs,
                'succeeded' => $response->degradedReason !== 'api_error',
                'served_from_cache' => $servedFromCache,
                'failure_reason' => $response->degradedReason,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record an AI interaction.', ['message' => $e->getMessage()]);
        }
    }
}
