<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\AiUsage;
use App\Ai\Contracts\ProviderDriver;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\ResolvedCredential;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use RuntimeException;

/**
 * One driver for every provider that speaks OpenAI Chat Completions.
 *
 * OpenAI, DeepSeek, Groq, Together, Mistral, xAI, OpenRouter, Ollama and
 * LM Studio all accept `POST {base}/chat/completions` with the same body. So
 * connecting a new one of them is a config entry and a base URL, not a class —
 * which is the whole reason this is written against the wire format rather
 * than against any single vendor's SDK.
 *
 * Raw HTTP rather than a package, deliberately. An SDK would tie this to one
 * vendor's idea of the shape and add a dependency per provider; the shape
 * itself is small, stable and shared.
 *
 * ── Structured output ────────────────────────────────────────────────────────
 *
 * Providers differ here, and the difference is worth being explicit about:
 *
 *   supports_json_schema  the schema is sent as `response_format.json_schema`
 *                         and the provider constrains generation to it
 *   otherwise             `response_format: {type: "json_object"}` guarantees
 *                         syntactically valid JSON but not the right shape, so
 *                         the schema goes in the prompt and the result is
 *                         checked afterwards
 *
 * The second path is genuinely weaker. Saying so in a comment beats discovering
 * it from a malformed booking six weeks in.
 */
final class OpenAiCompatibleDriver implements ProviderDriver
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AiCredentials $credentials,
    ) {}

    public function name(): string
    {
        return 'openai_compatible';
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
        $endpoint = $credential->baseUrl;

        if (blank($endpoint)) {
            throw new RuntimeException("Provider [{$credential->provider->id}] has no base URL configured.");
        }

        $startedAt = hrtime(true);

        $response = $this->http
            ->withToken($credential->apiKey)
            ->timeout((int) config('ai.request.timeout', 25))
            ->retry(2, 200, throw: false)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($endpoint, '/').'/chat/completions', $this->body($request, $credential));

        if ($response->failed()) {
            // The body can echo the request, so it never reaches the caller or
            // the log. The status is enough to act on.
            throw new RuntimeException(sprintf(
                '%s returned HTTP %d for a %s request.',
                $credential->displayName(),
                $response->status(),
                $request->task->value,
            ));
        }

        $payload = $response->json();
        $text = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new RuntimeException(
                $credential->displayName().' returned no message content ('
                .($payload['choices'][0]['finish_reason'] ?? 'unknown finish reason').').'
            );
        }

        $usage = $payload['usage'] ?? [];
        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return new AiResponse(
            data: $request->expectsJson() ? $this->decode($text, $request, $credential) : [],
            text: $text,
            driver: $credential->provider->id,
            model: (string) ($payload['model'] ?? $credential->model),
            usage: AiUsage::priced(
                rates: $credential->rates,
                inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
                outputTokens: (int) ($usage['completion_tokens'] ?? 0),
                // Providers that report a cache hit put it here; the rest omit
                // it and this stays zero.
                cachedInputTokens: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
            ),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiRequest $request, ResolvedCredential $credential): array
    {
        $system = $request->system;
        $schema = $request->schema;

        $body = [
            'model' => $credential->model,
            'max_tokens' => $request->maxTokens(),
            'messages' => [],
        ];

        if ($schema !== null && ! $credential->provider->supportsJsonSchema) {
            // JSON mode returns valid JSON but not necessarily the right
            // shape, and several providers additionally require the word
            // "json" to appear in the prompt before they will enable it.
            $system .= "\n\nReply with JSON only — no prose, no code fences — matching exactly this JSON schema:\n"
                .json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $body['messages'][] = ['role' => 'system', 'content' => $system];
        $body['messages'][] = ['role' => 'user', 'content' => $request->prompt];

        if ($schema !== null) {
            $body['response_format'] = $credential->provider->supportsJsonSchema
                ? [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $request->task->value,
                        'schema' => $schema,
                        'strict' => true,
                    ],
                ]
                : ['type' => 'json_object'];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text, AiRequest $request, ResolvedCredential $credential): array
    {
        // Some providers wrap JSON in a code fence despite being asked not to,
        // particularly on the prompt-only path. Stripping it is cheaper than
        // losing the response.
        $text = trim($text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf(
                '%s returned invalid JSON for %s: %s',
                $credential->displayName(),
                $request->task->value,
                $e->getMessage(),
            ), previous: $e);
        }

        return $decoded;
    }
}
