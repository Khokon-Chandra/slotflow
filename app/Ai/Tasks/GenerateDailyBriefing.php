<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use App\Ai\AiRequest;
use App\Ai\Contracts\AiClient;
use App\Domain\Reporting\DayStatistics;
use App\Enums\AiTask;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * The three lines the owner reads at 8am.
 *
 * Every number in the prompt is computed by DayStatistics first. The model is
 * given arithmetic it cannot get wrong and asked only to decide what matters
 * and say it in plain English — which is the split that makes an LLM feature
 * trustworthy rather than merely impressive.
 */
final class GenerateDailyBriefing
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly DayStatistics $statistics,
    ) {}

    /**
     * @return array{
     *     headline: string,
     *     bullets: list<array{text: string, tone: string}>,
     *     focus: string,
     *     stats: array<string, mixed>,
     *     ai: array<string, mixed>
     * }
     */
    public function __invoke(Tenant $tenant, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::now($tenant->timezone);
        $stats = $this->statistics->forDay($tenant, $date);

        $response = $this->ai->run(new AiRequest(
            task: AiTask::DailyBriefing,
            system: $this->system($tenant),
            prompt: $this->prompt($stats),
            schema: self::schema(),
            payload: $stats,
            // The diary changes during the day; a 15-minute-old briefing is
            // fine, an hour-old one is not. Keyed on the day plus the two
            // figures most likely to change.
            cacheKey: hash('xxh128', implode('|', [
                $tenant->id,
                $stats['date'],
                $stats['booking_count'],
                $stats['high_risk_count'],
                $stats['revenue_cents'],
            ])),
            maxTokens: 700,
        ));

        return [
            'headline' => (string) $response->get('headline', ''),
            'bullets' => $this->normaliseBullets($response->get('bullets', [])),
            'focus' => (string) $response->get('focus', ''),
            'stats' => $stats,
            'ai' => $response->provenance(),
        ];
    }

    private function system(Tenant $tenant): string
    {
        return <<<PROMPT
        You write the morning briefing for whoever runs {$tenant->name}.

        You are given the day's figures, already computed. Use them exactly as
        given — never recompute, never estimate, never round in a way that
        changes the number.

        Style:
        - Talk like a competent colleague, not a dashboard. Short sentences.
        - Say what needs doing, not what a good day it is.
        - Three or four bullets at most. If the day is quiet, say so in one
          line rather than padding.
        - `focus` is a single concrete action for today, or a plain statement
          that nothing needs attention. Do not invent work.
        - No emoji, no exclamation marks, no "Good morning".
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function prompt(array $stats): string
    {
        $perStaff = collect($stats['per_staff'])
            ->map(fn (array $row) => sprintf('  - %s: %d booking(s), %d booked minutes', $row['name'], $row['bookings'], $row['booked_minutes']))
            ->implode("\n") ?: '  - nobody is rostered';

        return <<<PROMPT
        Date: {$stats['date']}
        Appointments: {$stats['booking_count']}
        Flagged high no-show risk: {$stats['high_risk_count']}
        Expected takings: {$stats['revenue_cents']} minor units ({$stats['currency']})
        Capacity used: {$stats['utilisation_percent']}%
        First start: {$this->orDash($stats['first_start'])}
        Last end: {$this->orDash($stats['last_end'])}
        Largest gap: {$stats['largest_gap_minutes']} minutes starting {$this->orDash($stats['largest_gap_start'])}

        Per team member:
        {$perStaff}
        PROMPT;
    }

    private function orDash(?string $value): string
    {
        return $value ?? '—';
    }

    /**
     * @return list<array{text: string, tone: string}>
     */
    private function normaliseBullets(mixed $bullets): array
    {
        if (! is_array($bullets)) {
            return [];
        }

        $normalised = [];

        foreach ($bullets as $bullet) {
            if (! is_array($bullet) || ! isset($bullet['text'])) {
                continue;
            }

            $normalised[] = [
                'text' => (string) $bullet['text'],
                'tone' => in_array($bullet['tone'] ?? 'neutral', ['neutral', 'warning', 'positive'], true)
                    ? (string) $bullet['tone']
                    : 'neutral',
            ];
        }

        return array_slice($normalised, 0, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'bullets', 'focus'],
            'properties' => [
                'headline' => [
                    'type' => 'string',
                    'description' => 'One short line summarising the day. Under 60 characters.',
                ],
                'bullets' => [
                    'type' => 'array',
                    'maxItems' => 4,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'tone'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'tone' => [
                                'type' => 'string',
                                'enum' => ['neutral', 'warning', 'positive'],
                            ],
                        ],
                    ],
                ],
                'focus' => [
                    'type' => 'string',
                    'description' => 'One concrete action for today, or a statement that none is needed.',
                ],
            ],
        ];
    }
}
