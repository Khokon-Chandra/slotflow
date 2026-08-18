<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Credentials\ResolvedCredential;

/**
 * A driver that talks to a real model provider.
 *
 * `call()` takes the credential explicitly rather than resolving it, because
 * the manager has already resolved one and passing it removes any chance the
 * driver picks a different workspace's. There are two implementations and
 * three-plus providers: OpenAiCompatibleDriver serves every provider that
 * speaks Chat Completions, which is most of them.
 */
interface ProviderDriver extends AiClient
{
    public function call(AiRequest $request, ResolvedCredential $credential): AiResponse;
}
