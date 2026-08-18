<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\RequestOptions;
use App\Ai\Credentials\Contracts\VerifiesCredentials;
use App\Ai\Providers\Provider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks that a credential works, before it is stored.
 *
 * Both strategies list or retrieve models rather than sending a message: that
 * validates the credential *and* that the account can reach the model the
 * workspace wants, costs no tokens, and returns fast enough for the form to be
 * synchronous without feeling broken.
 *
 * Storing an unverified key would be worse than storing none. The workspace
 * would look configured, every AI call would quietly fall back, and the only
 * evidence would be a `degraded_reason` nobody was looking at.
 */
final class VerifyCredential implements VerifiesCredentials
{
    public function __construct(
        private readonly AnthropicClientFactory $anthropic,
        private readonly HttpFactory $http,
    ) {}

    public function __invoke(Provider $provider, string $apiKey, string $model, ?string $baseUrl = null): KeyVerification
    {
        try {
            return match ($provider->driver) {
                'anthropic' => $this->verifyAnthropic($apiKey, $model),
                'openai_compatible' => $this->verifyOpenAiCompatible($provider, $apiKey, $model, $baseUrl),
                default => KeyVerification::fail("No driver implements [{$provider->driver}]."),
            };
        } catch (Throwable $e) {
            // Never log the key, and never surface a raw exception to the
            // browser — an SDK or HTTP error can carry request context.
            Log::warning('AI credential verification failed.', [
                'provider' => $provider->id,
                'model' => $model,
                'exception' => $e::class,
            ]);

            return KeyVerification::fail(
                'Could not reach '.$provider->label.'. Check the address and your network, then try again.'
            );
        } finally {
            if ($provider->driver === 'anthropic') {
                // The key may be wrong, or about to be replaced. Either way it
                // should not sit in the client cache after this.
                $this->anthropic->forget($apiKey);
            }
        }
    }

    private function verifyAnthropic(string $apiKey, string $model): KeyVerification
    {
        try {
            $info = $this->anthropic->for($apiKey)->models->retrieve(
                $model,
                requestOptions: RequestOptions::with(timeout: 12.0, maxRetries: 1),
            );

            return KeyVerification::pass(
                model: $info->id,
                displayName: $info->displayName,
                contextWindow: $info->maxInputTokens,
            );
        } catch (APIStatusException $e) {
            return KeyVerification::fail($this->explain($e->status, 'Anthropic'));
        }
    }

    /**
     * `GET {base}/models` is the one endpoint every OpenAI-compatible provider
     * implements, and it needs no tokens.
     *
     * Whether the wanted model appears in the list is treated as a *hint*, not
     * a verdict: gateways and self-hosted runtimes routinely return a partial
     * list or none at all, and refusing a working credential because a listing
     * was incomplete would be worse than accepting one that later 404s on the
     * model — which the driver reports anyway.
     */
    private function verifyOpenAiCompatible(
        Provider $provider,
        string $apiKey,
        string $model,
        ?string $baseUrl,
    ): KeyVerification {
        $endpoint = $baseUrl ?: $provider->baseUrl;

        if (blank($endpoint)) {
            return KeyVerification::fail('This provider needs a base URL.');
        }

        $response = $this->http
            ->withToken($apiKey)
            ->timeout(12)
            ->acceptJson()
            ->get(rtrim($endpoint, '/').'/models');

        if ($response->failed()) {
            return KeyVerification::fail($this->explain($response->status(), $provider->label));
        }

        $ids = array_column((array) ($response->json('data') ?? []), 'id');
        $listed = $ids === [] || in_array($model, $ids, true);

        return KeyVerification::pass(
            model: $model,
            displayName: $provider->label.' · '.$model,
            contextWindow: null,
            note: $listed
                ? null
                : "The key works, but {$provider->label} did not list [{$model}] among its models. "
                    .'That is common with gateways and self-hosted runtimes — check the id if calls start failing.',
        );
    }

    private function explain(?int $status, string $providerLabel): string
    {
        if ($status === null) {
            return $providerLabel.' rejected the request. Check the key and try again.';
        }

        return match ($status) {
            401 => 'That key was rejected. Check you copied all of it, and that it has not been revoked.',
            403 => "That key is valid but not permitted here. Check the key's project or workspace in the {$providerLabel} console.",
            404 => 'That address does not look like an OpenAI-compatible API. Check the base URL — it usually ends in /v1.',
            429 => 'The key is rate limited right now. It is probably fine — try again in a moment.',
            default => "{$providerLabel} returned an error ({$status}). Try again shortly.",
        };
    }
}
