<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use Anthropic\Messages\Message;
use Anthropic\RequestOptions;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\AiUsage;
use App\Ai\Contracts\AiClient;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\ClaudeClientFactory;
use JsonException;
use RuntimeException;

/**
 * Calls the Anthropic Messages API.
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
final class ClaudeClient implements AiClient
{
    public function __construct(
        private readonly ClaudeClientFactory $clients,
        private readonly AiCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'claude';
    }

    public function run(AiRequest $request): AiResponse
    {
        $apiKey = $this->credentials->apiKey();

        if ($apiKey === null) {
            throw new RuntimeException('No Anthropic API key is configured for this workspace.');
        }

        $model = $this->credentials->model();
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
                timeout: (float) config('ai.claude.timeout', 25),
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
                model: $model,
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
