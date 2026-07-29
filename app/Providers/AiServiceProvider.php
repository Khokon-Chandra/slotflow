<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Ai\AiManager;
use App\Ai\Contracts\AiClient;
use App\Ai\Drivers\ClaudeClient;
use App\Ai\Narrators\AiRiskNarrator;
use App\Domain\Risk\Contracts\RiskNarrator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI layer.
 *
 * Note that the application depends on `AiClient` everywhere and on
 * `AiManager` in exactly one place — here. Tests bind a fake against the same
 * interface, so nothing in the codebase needs to know whether it is talking to
 * Anthropic, to a template, or to an array of canned answers.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The Anthropic SDK client is deferred: constructing it discovers a
        // PSR-18 implementation, which is wasted work on the many requests
        // that never touch AI.
        $this->app->singleton(Client::class, fn (): Client => new Client(
            apiKey: (string) config('ai.claude.api_key'),
        ));

        $this->app->singleton(ClaudeClient::class, fn (Container $app): ClaudeClient => new ClaudeClient(
            client: $app->make(Client::class),
            model: (string) config('ai.claude.model'),
        ));

        // Everything resolves the manager, never a concrete driver.
        $this->app->singleton(AiClient::class, AiManager::class);
        $this->app->singleton(AiManager::class);

        $this->app->bind(RiskNarrator::class, AiRiskNarrator::class);
    }
}
