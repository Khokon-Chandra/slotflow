<?php

declare(strict_types=1);

namespace App\Ai\Heuristics;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\Heuristic;
use App\Enums\RiskBand;

/**
 * Writes the risk sentence from the factors, without a model.
 *
 * Because the score itself is computed deterministically upstream, this
 * fallback loses nothing that matters — only the fluency of the wording.
 */
final class RiskNarrativeHeuristic implements Heuristic
{
    public function handle(AiRequest $request): AiResponse
    {
        /** @var array{band: string, score: int, factors: list<array{code: string, label: string, points: int}>, customer_name: string, service_name: string, starts_at_local: string} $payload */
        $payload = $request->payload;

        $band = RiskBand::from($payload['band']);
        $increasing = array_values(array_filter($payload['factors'], fn (array $f) => $f['points'] > 0));
        $reducing = array_values(array_filter($payload['factors'], fn (array $f) => $f['points'] < 0));

        $rationale = $this->rationale($band, $payload, $increasing, $reducing);
        $action = $this->action($band, $increasing);

        return AiResponse::heuristic(
            ['rationale' => $rationale, 'recommended_action' => $action],
            $rationale,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{code: string, label: string, points: int}>  $increasing
     * @param  list<array{code: string, label: string, points: int}>  $reducing
     */
    private function rationale(RiskBand $band, array $payload, array $increasing, array $reducing): string
    {
        $name = $payload['customer_name'] ?? 'This customer';
        $score = $payload['score'] ?? 0;

        $lead = match ($band) {
            RiskBand::High => "{$name} scores {$score}/100 — worth a personal reminder.",
            RiskBand::Medium => "{$name} scores {$score}/100 — keep an eye on it.",
            RiskBand::Low => "{$name} scores {$score}/100 — nothing unusual here.",
        };

        $reasons = array_slice(array_map(
            fn (array $f) => lcfirst($f['label']),
            $increasing,
        ), 0, 2);

        if ($reasons !== []) {
            $lead .= ' Mainly '.$this->joinWords($reasons).'.';
        }

        if ($reducing !== []) {
            $lead .= ' Working in their favour: '.lcfirst($reducing[0]['label']).'.';
        }

        return $lead;
    }

    /**
     * @param  list<array{code: string, label: string, points: int}>  $increasing
     */
    private function action(RiskBand $band, array $increasing): string
    {
        $codes = array_column($increasing, 'code');

        if (in_array('no_phone', $codes, true)) {
            return 'Ask for a phone number so a reminder can actually reach them.';
        }

        return match ($band) {
            RiskBand::High => 'Confirm by phone the day before, or hold the slot with a deposit.',
            RiskBand::Medium => 'Send the standard reminder 24 hours ahead.',
            RiskBand::Low => 'No action needed.',
        };
    }

    /**
     * @param  list<string>  $words
     */
    private function joinWords(array $words): string
    {
        if (count($words) === 1) {
            return $words[0];
        }

        $last = array_pop($words);

        return implode(', ', $words).' and '.$last;
    }
}
