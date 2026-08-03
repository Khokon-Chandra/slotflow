<?php

declare(strict_types=1);

namespace App\Ai\Heuristics;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\Heuristic;

/**
 * Assembles the morning briefing from the already-computed day statistics.
 *
 * The numbers are the same ones Claude is given; only the prose differs.
 */
final class DailyBriefingHeuristic implements Heuristic
{
    public function handle(AiRequest $request): AiResponse
    {
        /**
         * @var array{
         *     date: string, booking_count: int, high_risk_count: int,
         *     revenue_cents: int, currency: string, utilisation_percent: int,
         *     first_start: string|null, last_end: string|null,
         *     largest_gap_minutes: int, largest_gap_start: string|null,
         *     busiest_staff: string|null, quiet_staff: string|null
         * } $p
         */
        $p = $request->payload;

        $bullets = [];

        $bullets[] = [
            'text' => $p['booking_count'] === 0
                ? 'Nothing in the diary today.'
                : sprintf(
                    '%d appointment%s, %s from %s to %s.',
                    $p['booking_count'],
                    $p['booking_count'] === 1 ? '' : 's',
                    $this->money($p['revenue_cents'], $p['currency']),
                    $p['first_start'] ?? '—',
                    $p['last_end'] ?? '—',
                ),
            'tone' => $p['booking_count'] === 0 ? 'neutral' : 'positive',
        ];

        if ($p['high_risk_count'] > 0) {
            $bullets[] = [
                'text' => sprintf(
                    '%d booking%s flagged as high no-show risk — worth a confirmation call.',
                    $p['high_risk_count'],
                    $p['high_risk_count'] === 1 ? '' : 's',
                ),
                'tone' => 'warning',
            ];
        }

        if ($p['largest_gap_minutes'] >= 60 && $p['largest_gap_start'] !== null) {
            $bullets[] = [
                'text' => sprintf(
                    'A %s gap from %s could take a walk-in.',
                    $this->duration($p['largest_gap_minutes']),
                    $p['largest_gap_start'],
                ),
                'tone' => 'neutral',
            ];
        }

        if ($p['busiest_staff'] !== null && $p['quiet_staff'] !== null && $p['busiest_staff'] !== $p['quiet_staff']) {
            $bullets[] = [
                'text' => sprintf(
                    '%s is carrying the day; %s has room if anything needs moving.',
                    $p['busiest_staff'],
                    $p['quiet_staff'],
                ),
                'tone' => 'neutral',
            ];
        }

        $data = [
            'headline' => $this->headline($p),
            'bullets' => array_slice($bullets, 0, 4),
            'focus' => $this->focus($p),
        ];

        return AiResponse::heuristic($data, $data['headline']);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function headline(array $p): string
    {
        return match (true) {
            $p['booking_count'] === 0 => 'A clear day.',
            $p['high_risk_count'] > 0 => sprintf('%d booked, %d to double-check.', $p['booking_count'], $p['high_risk_count']),
            $p['utilisation_percent'] >= 85 => sprintf('Full day — %d%% booked.', $p['utilisation_percent']),
            default => sprintf('%d appointments, %d%% of capacity.', $p['booking_count'], $p['utilisation_percent']),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function focus(array $p): string
    {
        return match (true) {
            $p['high_risk_count'] > 0 => 'Confirm the flagged bookings before lunch.',
            $p['booking_count'] === 0 => 'A good day to chase the customers who have not been in for a while.',
            $p['utilisation_percent'] < 50 && $p['largest_gap_minutes'] >= 90 => 'Plenty of open time — worth promoting today\'s gaps.',
            default => 'Nothing needs your attention beyond the day itself.',
        };
    }

    private function money(int $cents, string $currency): string
    {
        return sprintf('%s%s', $currency === 'EUR' ? '€' : ($currency === 'USD' ? '$' : $currency.' '), number_format($cents / 100, 2));
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} minute";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} hour" : "{$hours}h {$rest}m";
    }
}
