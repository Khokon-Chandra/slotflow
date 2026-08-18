<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use App\Ai\Providers\Provider;

/**
 * Everything a driver needs to make one call: who to call, with what key, as
 * what model, and what it costs.
 *
 * Resolved per call rather than injected once. A queue worker handles jobs for
 * several workspaces in one process, and a credential captured at construction
 * is a credential used for somebody else's tenant.
 */
final readonly class ResolvedCredential
{
    /**
     * @param  'workspace'|'platform'  $source
     * @param  array{input: float, output: float}|null  $rates
     */
    public function __construct(
        public Provider $provider,
        public string $apiKey,
        public string $model,
        public ?string $baseUrl,
        public string $source,
        public ?array $rates = null,
        public ?string $label = null,
    ) {}

    public function displayName(): string
    {
        return $this->label ?? $this->provider->label;
    }

    /**
     * Whether this application can turn tokens into money for this model.
     *
     * When false the call still happens and the token counts are still
     * recorded — only the cost is unknown, and it is reported as untracked
     * rather than as zero. A budget you cannot measure is not a budget, and
     * pretending otherwise is how a spend ceiling silently stops working.
     */
    public function tracksSpend(): bool
    {
        return $this->rates !== null;
    }
}
