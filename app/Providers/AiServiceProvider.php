<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AiManager;
use App\Ai\Contracts\AiClient;
use App\Ai\Credentials\ClaudeClientFactory;
use App\Ai\Credentials\Contracts\VerifiesApiKeys;
use App\Ai\Credentials\VerifyApiKey;
use App\Ai\Narrators\AiRiskNarrator;
use App\Domain\Risk\Contracts\RiskNarrator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI layer.
 *
 * The application depends on `AiClient` everywhere and on `AiManager` in
 * exactly one place — here. Tests bind a fake against the same interface, so
 * nothing in the codebase needs to know whether it is talking to Anthropic, to
 * a template, or to an array of canned answers.
 *
 * Note what is *not* a singleton. The Anthropic SDK client is built per key by
 * ClaudeClientFactory, because a workspace may bring its own credential and a
 * queue worker serves several workspaces in one process. A shared client would
 * send one tenant's request on another tenant's key, and nothing would say so.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The factory is a singleton; the clients inside it are keyed by the
        // API key, so reuse never crosses a workspace boundary.
        $this->app->singleton(ClaudeClientFactory::class);

        // An interface so the suite can verify keys without a live credential.
        $this->app->bind(VerifiesApiKeys::class, VerifyApiKey::class);

        // Everything resolves the manager, never a concrete driver.
        $this->app->singleton(AiClient::class, AiManager::class);
        $this->app->singleton(AiManager::class);

        $this->app->bind(RiskNarrator::class, AiRiskNarrator::class);
    }
}
