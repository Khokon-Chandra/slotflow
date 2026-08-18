<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

/**
 * The result of checking a key against the Anthropic API.
 */
final readonly class KeyVerification
{
    private function __construct(
        public bool $ok,
        public ?string $model = null,
        public ?string $displayName = null,
        public ?int $contextWindow = null,
        public ?string $error = null,
        // A warning that does not block saving — "the key works, but the
        // provider did not list that model".
        public ?string $note = null,
    ) {}

    public static function pass(
        string $model,
        string $displayName,
        ?int $contextWindow = null,
        ?string $note = null,
    ): self {
        return new self(
            ok: true,
            model: $model,
            displayName: $displayName,
            contextWindow: $contextWindow,
            note: $note,
        );
    }

    public static function fail(string $error): self
    {
        return new self(ok: false, error: $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'model' => $this->model,
            'display_name' => $this->displayName,
            'context_window' => $this->contextWindow,
            'error' => $this->error,
            'note' => $this->note,
        ];
    }
}
