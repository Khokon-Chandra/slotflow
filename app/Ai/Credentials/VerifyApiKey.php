<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\RequestOptions;
use App\Ai\Credentials\Contracts\VerifiesApiKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks that a key works, before it is stored.
 *
 * It retrieves the model rather than sending a message. That call validates
 * the credential *and* that this account can reach the model the workspace
 * wants to use, costs no tokens, and returns in well under a second — so
 * saving a key can be synchronous without making the form feel broken.
 *
 * Storing an unverified key would be worse than storing none: the workspace
 * would look configured, every AI call would quietly fall back, and the only
 * evidence would be a `degraded_reason` nobody was looking at.
 */
final class VerifyApiKey implements VerifiesApiKeys
{
    public function __construct(private readonly ClaudeClientFactory $clients) {}

    public function __invoke(string $apiKey, string $model): KeyVerification
    {
        try {
            $info = $this->clients->for($apiKey)->models->retrieve(
                $model,
                requestOptions: RequestOptions::with(timeout: 12.0, maxRetries: 1),
            );

            return KeyVerification::pass(
                model: $info->id,
                displayName: $info->displayName,
                contextWindow: $info->maxInputTokens,
            );
        } catch (APIStatusException $e) {
            return KeyVerification::fail($this->explain($e));
        } catch (Throwable $e) {
            // Never log the key, and never surface a raw exception message to
            // the browser — an SDK error can carry request context.
            Log::warning('AI key verification failed.', [
                'exception' => $e::class,
                'model' => $model,
            ]);

            return KeyVerification::fail(
                'Could not reach the Anthropic API. Check your network and try again.'
            );
        } finally {
            // The key may be wrong, or about to be replaced. Either way it
            // should not sit in the client cache after this.
            $this->clients->forget($apiKey);
        }
    }

    private function explain(APIStatusException $e): string
    {
        // `status` is nullable on the SDK exception, so it cannot go straight
        // into the message — "returned an error ()" helps nobody.
        if ($e->status === null) {
            return 'Anthropic rejected the request. Check the key and try again.';
        }

        return match ($e->status) {
            401 => 'That key was rejected. Check you copied all of it, and that it has not been revoked.',
            403 => 'That key is valid but not permitted to use this model. Pick another model, or check the key\'s workspace in the Anthropic console.',
            404 => 'That model does not exist, or this key cannot see it.',
            429 => 'The key is rate limited right now. It is probably fine — try again in a moment.',
            default => 'Anthropic returned an error ('.$e->status.'). Try again shortly.',
        };
    }
}
