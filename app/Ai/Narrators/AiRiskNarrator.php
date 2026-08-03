<?php

declare(strict_types=1);

namespace App\Ai\Narrators;

use App\Ai\AiRequest;
use App\Ai\Contracts\AiClient;
use App\Domain\Risk\Contracts\RiskNarrator;
use App\Domain\Risk\RiskProfile;
use App\Enums\AiTask;
use App\Models\Booking;

/**
 * The model's half of no-show risk: the sentence, not the score.
 *
 * It receives the factors and the number that were already computed and is
 * explicitly told not to argue with them. That constraint is the point —
 * an explanation that can disagree with the thing it explains is worse than
 * no explanation, because people believe it.
 */
final class AiRiskNarrator implements RiskNarrator
{
    public function __construct(private readonly AiClient $ai) {}

    public function narrate(Booking $booking, RiskProfile $profile): array
    {
        $booking->loadMissing(['customer', 'service']);

        $payload = [
            'score' => $profile->score,
            'band' => $profile->band->value,
            'factors' => $profile->toArray(),
            'customer_name' => $booking->customer->name,
            'service_name' => $booking->service->name,
            'starts_at_local' => $booking->startsAtForCustomer()->format('D j M, H:i'),
        ];

        $response = $this->ai->run(new AiRequest(
            task: AiTask::NoShowRationale,
            system: $this->system(),
            prompt: $this->prompt($payload),
            schema: self::schema(),
            payload: $payload,
            // Two bookings with the same score and the same factors deserve
            // the same sentence — and only one API call.
            cacheKey: hash('xxh128', json_encode([
                $profile->score,
                array_column($profile->toArray(), 'code'),
            ], JSON_THROW_ON_ERROR)),
            maxTokens: 500,
        ));

        return [
            'rationale' => (string) $response->get('rationale', ''),
            'recommended_action' => (string) $response->get('recommended_action', ''),
            'driver' => $response->driver,
            'model' => $response->model,
        ];
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You explain a no-show risk score to the person running a small
        appointment business.

        The score and its contributing factors were computed by the
        application, not by you. Treat them as fact.

        Rules:
        - Never restate, recompute, dispute or hedge the score.
        - Two sentences maximum for `rationale`. Name the one or two factors
          that actually drove it.
        - `recommended_action` is one concrete thing a receptionist can do
          today, or "No action needed."
        - This is an operational heuristic about a booking, not a judgement
          about a person. No speculation about motives, finances, or
          circumstances. Do not suggest refusing service.
        - Plain language. No jargon, no percentages you were not given.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function prompt(array $payload): string
    {
        $factors = collect($payload['factors'])
            ->map(fn (array $f) => sprintf('  - %s (%+d)', $f['label'], $f['points']))
            ->implode("\n");

        return <<<PROMPT
        Customer: {$payload['customer_name']}
        Service: {$payload['service_name']}
        Appointment: {$payload['starts_at_local']}
        Score: {$payload['score']}/100 ({$payload['band']})

        Contributing factors:
        {$factors}
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rationale', 'recommended_action'],
            'properties' => [
                'rationale' => [
                    'type' => 'string',
                    'description' => 'Up to two sentences explaining the score.',
                ],
                'recommended_action' => [
                    'type' => 'string',
                    'description' => 'One concrete action, or "No action needed."',
                ],
            ],
        ];
    }
}
