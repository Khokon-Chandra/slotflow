<?php

declare(strict_types=1);

namespace App\Ai\Heuristics;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\Heuristic;

/**
 * A serviceable service description assembled from the service's own fields.
 *
 * Plainly not as good as the model's version — which is exactly why the admin
 * UI labels which one it is showing.
 */
final class ServiceCopyHeuristic implements Heuristic
{
    public function handle(AiRequest $request): AiResponse
    {
        /** @var array{name: string, duration_minutes: int, price_cents: int, currency: string, audience: string|null, keywords: string|null, business_name: string} $p */
        $p = $request->payload;

        $duration = $this->duration($p['duration_minutes']);
        $price = $this->money($p['price_cents'], $p['currency']);
        $audience = $p['audience'] ?? 'you';

        $description = sprintf(
            '%s at %s. A %s appointment for %s, booked online and confirmed straight away. %s',
            $p['name'],
            $p['business_name'],
            $duration,
            $audience,
            $p['price_cents'] === 0
                ? 'No charge.'
                : "{$price}, payable at the appointment.",
        );

        $data = [
            'description' => $description,
            'highlights' => array_values(array_filter([
                "{$duration} appointment",
                $p['price_cents'] === 0 ? 'Free' : $price,
                'Instant online confirmation',
            ])),
        ];

        return AiResponse::heuristic($data, $description);
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}-minute";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? ($hours === 1 ? 'one-hour' : "{$hours}-hour")
            : "{$hours}h {$rest}m";
    }

    private function money(int $cents, string $currency): string
    {
        $symbol = match ($currency) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => $currency.' ',
        };

        return $symbol.number_format($cents / 100, 2);
    }
}
