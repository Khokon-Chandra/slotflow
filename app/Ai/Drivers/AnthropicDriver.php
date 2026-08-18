<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use Anthropic\Messages\Message;
use Anthropic\RequestOptions;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\AiUsage;
use App\Ai\Contracts\ProviderDriver;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\AnthropicClientFactory;
use App\Ai\Credentials\ResolvedCredential;
use JsonException;
use RuntimeException;

/**
 * Calls the Anthropic Messages API.
 *
 * One of two drivers. The other, OpenAiCompatibleDriver, covers every provider
 * that speaks the OpenAI Chat Completions shape. Anthropic keeps its own
 * because its request and response shapes differ, and because the official SDK
 * is worth having for the provider this application defaults to.
 *
 * Four choices worth pointing at:
 *
 *  1. **Credentials are resolved per call, not injected once.** A workspace
 *     may bring its own key, and a queue worker handles jobs for several
 *     workspaces in one process. A client captured at construction would send
 *     one tenant's request on another tenant's key — and would do it silently.
 *
 *  2. **Structured output, not "please reply with JSON".** Every task that
 *     needs data passes a JSON schema through `outputConfig.format`, so the
 *     response is constrained at generation time. Asking politely in the
 *     prompt and hoping `json_decode` works is the single most common way LLM
 *     features fail in production.
 *
 *  3. **Effort `low` by default.** These tasks are short, well-specified
 *     extractions running inside a web request. Effort is the dial that
 *     matters far more than model choice for latency and spend on work like
 *     this; the model stays capable, it just does not deliberate over
 *     "next Tuesday afternoon".
 *
 *  4. **A short timeout and one retry.** A booking page cannot wait 60
 *     seconds. When the budget is gone the manager above catches the failure
 *     and serves the heuristic result, which is a worse answer arriving on
 *     time rather than a better one arriving never.
 */
final class AnthropicDriver implements ProviderDriver
{
    public function __construct(
        private readonly AnthropicClientFactory $clients,
        private readonly AiCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function run(AiRequest $request): AiResponse
    {
        $credential = $this->credentials->resolve();

        if ($credential === null) {
            throw new RuntimeException('No AI credential is configured for this workspace.');
        }

        return $this->call($request, $credential);
    }

    public function call(AiRequest $request, ResolvedCredential $credential): AiResponse
    {
        $apiKey = $credential->apiKey;
        $model = $credential->model;
        $startedAt = hrtime(true);

        $message = $this->clients->for($apiKey)->messages->create(
            maxTokens: $request->maxTokens(),
            messages: [
                ['role' => 'user', 'content' => $request->prompt],
            ],
            model: $model,
            outputConfig: $this->outputConfig($request),
            system: $request->system,
            requestOptions: RequestOptions::with(
                timeout: (float) config('ai.request.timeout', 25),
                maxRetries: 1,
            ),
        );

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $text = $this->firstTextBlock($message);

        return new AiResponse(
            data: $request->expectsJson() ? $this->decode($text, $request) : [],
            text: $text,
            driver: $this->name(),
            model: $message->model,
            usage: AiUsage::priced(
                rates: $credential->rates,
                inputTokens: $message->usage->inputTokens,
                outputTokens: $message->usage->outputTokens,
                cachedInputTokens: $message->usage->cacheReadInputTokens ?? 0,
            ),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function outputConfig(AiRequest $request): array
    {
        $config = ['effort' => $request->effort()];

        if ($request->schema !== null) {
            $config['format'] = [
                'type' => 'json_schema',
                'schema' => $request->schema,
            ];
        }

        return $config;
    }

    /**
     * Content is a list of polymorphic blocks. Reaching for `content[0]->text`
     * breaks the moment a thinking block arrives first, so walk the list.
     */
    private function firstTextBlock(Message $message): string
    {
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new RuntimeException(
            'The model returned no text block (stop reason: '.($message->stopReason ?? 'unknown').').'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text, AiRequest $request): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Structured output for {$request->task->value} was not valid JSON: {$e->getMessage()}",
                previous: $e,
            );
        }

        return $decoded;
    }
}
