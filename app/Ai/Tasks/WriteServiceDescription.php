<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use App\Ai\AiRequest;
use App\Ai\Contracts\AiClient;
use App\Enums\AiTask;
use App\Models\Tenant;

/**
 * Writes the customer-facing description for a service.
 *
 * A small, unglamorous problem that is real: the person who cuts hair well is
 * not necessarily the person who writes well, and an empty description costs
 * bookings. The output lands in a form field the owner edits before saving —
 * it is a draft, never a publish.
 */
final class WriteServiceDescription
{
    public function __construct(private readonly AiClient $ai) {}

    /**
     * @param  array{name: string, duration_minutes: int, price_cents: int, audience?: string|null, keywords?: string|null, tone?: string|null}  $input
     * @return array{description: string, highlights: list<string>, ai: array<string, mixed>}
     */
    public function __invoke(Tenant $tenant, array $input): array
    {
        $payload = [
            'name' => $input['name'],
            'duration_minutes' => $input['duration_minutes'],
            'price_cents' => $input['price_cents'],
            'currency' => $tenant->currency,
            'audience' => $input['audience'] ?? null,
            'keywords' => $input['keywords'] ?? null,
            'business_name' => $tenant->name,
        ];

        $response = $this->ai->run(new AiRequest(
            task: AiTask::ServiceCopy,
            system: $this->system($tenant, $input['tone'] ?? null),
            prompt: $this->prompt($payload),
            schema: self::schema(),
            payload: $payload,
            cacheKey: hash('xxh128', json_encode($payload, JSON_THROW_ON_ERROR)),
            maxTokens: 800,
        ));

        /** @var list<string> $highlights */
        $highlights = array_values(array_filter(
            array_map('strval', (array) $response->get('highlights', [])),
            fn (string $h) => $h !== '',
        ));

        return [
            'description' => (string) $response->get('description', ''),
            'highlights' => array_slice($highlights, 0, 3),
            'ai' => $response->provenance(),
        ];
    }

    private function system(Tenant $tenant, ?string $tone): string
    {
        $toneLine = filled($tone)
            ? "Match this tone: {$tone}."
            : 'Keep the tone warm and matter-of-fact.';

        return <<<PROMPT
        You write short service descriptions for {$tenant->name}'s online booking page.

        {$toneLine}

        Rules:
        - Two or three sentences. Under 320 characters.
        - Describe what the appointment involves and who it suits.
        - Use only the facts given. Never invent qualifications, guarantees,
          results, ingredients or medical claims.
        - No superlatives, no "unleash", no "elevate", no exclamation marks.
        - `highlights` are three short noun phrases, under 30 characters each.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prompt(array $payload): string
    {
        $lines = [
            "Service: {$payload['name']}",
            "Duration: {$payload['duration_minutes']} minutes",
            'Price: '.($payload['price_cents'] > 0
                ? number_format($payload['price_cents'] / 100, 2).' '.$payload['currency']
                : 'free'),
        ];

        if (filled($payload['audience'])) {
            $lines[] = "Who it is for: {$payload['audience']}";
        }

        if (filled($payload['keywords'])) {
            $lines[] = "Points to include: {$payload['keywords']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['description', 'highlights'],
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'Two or three sentences, under 320 characters.',
                ],
                'highlights' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
