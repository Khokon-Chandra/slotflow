<?php

declare(strict_types=1);

namespace App\Ai\Heuristics;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\Heuristic;
use Carbon\CarbonImmutable;

/**
 * Parses "can I get a haircut next Tuesday afternoon?" without a model.
 *
 * It handles the phrasings people actually type — relative days, weekday
 * names, parts of the day, explicit clock times, "asap" — and matches the
 * service by scored token overlap against the tenant's own service names.
 *
 * It is meaningfully worse than Claude at anything unusual ("sometime after
 * my shift ends, but not too early"), and that is fine: it fails by returning
 * a *wider* window, never a wrong one. The slots the customer is then shown
 * come from the availability engine either way, so the worst case is that
 * they scroll a little.
 */
final class BookingIntentHeuristic implements Heuristic
{
    private const array WEEKDAYS = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    /** Words too common to tell two service names apart. */
    private const array STOP_WORDS = [
        'a', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'book', 'booking', 'can',
        'could', 'do', 'for', 'get', 'have', 'i', 'id', 'if', 'in', 'is', 'it',
        'like', 'me', 'my', 'need', 'of', 'on', 'or', 'please', 'possible', 'the',
        'to', 'want', 'with', 'would', 'you', 'your', 'appointment', 'session',
    ];

    public function handle(AiRequest $request): AiResponse
    {
        // Deliberately not narrowed to an exact shape: the payload crosses a
        // driver boundary, and every read below tolerates a missing key.
        $payload = $request->payload;

        $text = mb_strtolower(trim($payload['text'] ?? ''));
        $timezone = $payload['timezone'] ?? 'UTC';
        $today = CarbonImmutable::parse($payload['today'] ?? 'today', $timezone)->startOfDay();

        [$from, $until] = $this->resolveDateRange($text, $today);
        [$timeOfDay, $earliest, $latest] = $this->resolveTimeOfDay($text);

        $service = $this->matchService($text, $payload['services'] ?? []);
        $staffId = $this->matchStaff($text, $payload['staff'] ?? []);

        $data = [
            'service_id' => $service['id'],
            'confidence' => $service['confidence'],
            'staff_id' => $staffId,
            'date_from' => $from->toDateString(),
            'date_until' => $until->toDateString(),
            'time_of_day' => $timeOfDay,
            'earliest_time' => $earliest,
            'latest_time' => $latest,
            'summary' => $this->summarise($service['name'], $from, $until, $timeOfDay, $today),
            'clarification' => $service['id'] === null
                ? 'Which service did you have in mind?'
                : null,
        ];

        return AiResponse::heuristic($data, $data['summary']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDateRange(string $text, CarbonImmutable $today): array
    {
        // Explicit ISO date wins over everything else.
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m) === 1) {
            $date = CarbonImmutable::parse($m[0], $today->timezone)->startOfDay();

            return [$date, $date];
        }

        if (str_contains($text, 'today') || str_contains($text, 'tonight')) {
            return [$today, $today];
        }

        if (str_contains($text, 'tomorrow')) {
            return [$today->addDay(), $today->addDay()];
        }

        if (preg_match('/\bin (\d{1,2}) days?\b/', $text, $m) === 1) {
            $date = $today->addDays((int) $m[1]);

            return [$date, $date];
        }

        // "next tuesday" means the Tuesday of next week; a bare "tuesday"
        // means the next one to come round, today included.
        $nextWeek = str_contains($text, 'next week') || preg_match('/\bnext\s+\w+/', $text) === 1;

        foreach (self::WEEKDAYS as $word => $dayOfWeek) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $text) !== 1) {
                continue;
            }

            $target = $today->next($dayOfWeek);

            if ($today->dayOfWeek === $dayOfWeek && ! str_contains($text, 'next')) {
                $target = $today;
            }

            // "next Tuesday" is genuinely ambiguous in English: on a Wednesday
            // some people mean the Tuesday six days away and some mean the one
            // after that. Rather than pick a reading and be wrong for half of
            // them, search both and let the customer see the dates. Slots come
            // back in time order, so the nearer Tuesday is offered first.
            if (str_contains($text, 'next '.$word)) {
                return [$target, $target->addWeek()];
            }

            return [$target, $target];
        }

        if (str_contains($text, 'this week')) {
            return [$today, $today->endOfWeek()->startOfDay()];
        }

        if ($nextWeek) {
            $start = $today->addWeek()->startOfWeek();

            return [$start, $start->addDays(6)];
        }

        if (str_contains($text, 'this weekend')) {
            return [$today->next(CarbonImmutable::SATURDAY), $today->next(CarbonImmutable::SUNDAY)];
        }

        if (preg_match('/\b(asap|as soon as possible|earliest|any ?time|whenever|soonest)\b/', $text) === 1) {
            return [$today, $today->addDays(7)];
        }

        // No date signal at all: search the coming week rather than guess.
        return [$today, $today->addDays(7)];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function resolveTimeOfDay(string $text): array
    {
        // An explicit clock time is the strongest signal: "around 3pm",
        // "at 14:30", "after 5".
        if (preg_match('/\b(?:at|around|after|from)\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/', $text, $m) === 1) {
            $hour = (int) $m[1];
            $minute = (int) ($m[2] ?? 0);
            $meridiem = $m[3] ?? null;

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            } elseif ($meridiem === null && $hour <= 7) {
                // "at 5" in a booking context means the afternoon.
                $hour += 12;
            }

            if ($hour <= 23) {
                $earliest = sprintf('%02d:%02d', $hour, $minute);
                $latest = sprintf('%02d:%02d', min(23, $hour + 2), $minute);

                return [$this->bandForHour($hour), $earliest, $latest];
            }
        }

        return match (true) {
            (bool) preg_match('/\b(morning|early|before noon|first thing)\b/', $text) => ['morning', '06:00', '12:00'],
            (bool) preg_match('/\b(lunch ?time|midday|noon)\b/', $text) => ['afternoon', '11:30', '14:00'],
            (bool) preg_match('/\b(afternoon|after lunch)\b/', $text) => ['afternoon', '12:00', '17:00'],
            (bool) preg_match('/\b(evening|after work|late|tonight|after 5|after five)\b/', $text) => ['evening', '17:00', '21:00'],
            default => ['any', null, null],
        };
    }

    private function bandForHour(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            default => 'evening',
        };
    }

    /**
     * Pick the service the customer means.
     *
     * Three passes, strongest evidence first:
     *
     *   1. a keyword *phrase* the owner configured ("hair cut", "line up")
     *   2. a single keyword or a word from the service name
     *   3. a fuzzy match, for plurals and typos
     *
     * Keywords matter more than they look. Customers do not read your menu
     * before typing — they ask for "a haircut" when the service is called
     * "Cut & finish", and no amount of string similarity bridges that. The
     * longest phrase wins, so "hair cut" beats a bare "hair".
     *
     * Below the confidence floor the method returns null rather than its best
     * guess. Asking "which service did you mean?" costs the customer one tap;
     * booking them into the wrong chair costs them an afternoon.
     *
     * @param  list<array<string, mixed>>  $services
     * @return array{id: int|null, name: string|null, confidence: string}
     */
    private function matchService(string $text, array $services): array
    {
        $words = $this->tokenise($text);

        if ($services === []) {
            return ['id' => null, 'name' => null, 'confidence' => 'low'];
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($services as $service) {
            $score = $this->scoreService($text, $words, $service);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $service;
            }
        }

        if ($best === null || $bestScore < 1.5) {
            return ['id' => null, 'name' => null, 'confidence' => 'low'];
        }

        return [
            'id' => (int) $best['id'],
            'name' => (string) $best['name'],
            'confidence' => match (true) {
                $bestScore >= 3.0 => 'high',
                $bestScore >= 2.0 => 'medium',
                default => 'low',
            },
        ];
    }

    /**
     * @param  list<string>  $words
     * @param  array<string, mixed>  $service
     */
    private function scoreService(string $text, array $words, array $service): float
    {
        $score = 0.0;

        // Pass 1 — owner-configured keywords. A multi-word phrase found in the
        // raw text is the strongest signal there is, and longer phrases beat
        // shorter ones so "hair cut" wins over "hair".
        foreach ($this->keywords($service) as $keyword) {
            if (str_contains(' '.$text.' ', ' '.$keyword.' ')) {
                $score = max($score, str_contains($keyword, ' ') ? 4.0 : 3.0);

                continue;
            }

            // "haircut" written as one word, or "cuts" for "cut".
            if (! str_contains($keyword, ' ') && mb_strlen($keyword) >= 3) {
                foreach ($words as $word) {
                    if ($word === $keyword) {
                        $score = max($score, 3.0);
                    } elseif (mb_strlen($word) > mb_strlen($keyword) && str_contains($word, $keyword)) {
                        // Weight compound hits by how much of the word the
                        // keyword accounts for: "haircut" is mostly "cut",
                        // "consultation" is barely "cons".
                        $score = max($score, 2.5 * (mb_strlen($keyword) / mb_strlen($word)) + 1.0);
                    }
                }
            }
        }

        if ($words === []) {
            return $score;
        }

        // Pass 2 — words from the service's own name.
        $nameTokens = $this->tokenise((string) ($service['name'] ?? ''));
        $descriptionTokens = $this->tokenise((string) ($service['description'] ?? ''));

        foreach ($words as $word) {
            if (in_array($word, $nameTokens, true)) {
                $score += 2.0;
            } elseif ($this->fuzzyContains($word, $nameTokens)) {
                $score += 1.0;
            } elseif (in_array($word, $descriptionTokens, true)) {
                $score += 0.4;
            }
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private function keywords(array $service): array
    {
        $raw = (string) ($service['keywords'] ?? '');

        if ($raw === '') {
            return [];
        }

        $keywords = array_values(array_filter(array_map(
            fn (string $k) => trim(mb_strtolower($k)),
            explode(',', $raw),
        )));

        // Longest first, so a phrase is tested before its own words.
        usort($keywords, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $keywords;
    }

    /**
     * @param  list<array<string, mixed>>  $staff
     */
    private function matchStaff(string $text, array $staff): ?int
    {
        foreach ($staff as $member) {
            $first = mb_strtolower(strtok((string) ($member['name'] ?? ''), ' ') ?: '');

            if ($first !== '' && preg_match('/\b'.preg_quote($first, '/').'\b/', $text) === 1) {
                return (int) $member['id'];
            }
        }

        return null;
    }

    /**
     * Cheap stemming: "cutting"/"cuts" should find "cut". Levenshtein on top
     * catches the ordinary typo without dragging in a similarity library.
     *
     * @param  list<string>  $candidates
     */
    private function fuzzyContains(string $word, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (mb_strlen($word) >= 4 && mb_strlen($candidate) >= 4) {
                if (str_starts_with($candidate, mb_substr($word, 0, 4))) {
                    return true;
                }

                if (levenshtein($word, $candidate) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function tokenise(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $word) => mb_strlen($word) > 2 && ! in_array($word, self::STOP_WORDS, true),
        ));
    }

    private function summarise(
        ?string $serviceName,
        CarbonImmutable $from,
        CarbonImmutable $until,
        string $timeOfDay,
        CarbonImmutable $today,
    ): string {
        $what = $serviceName ?? 'an appointment';

        $when = match (true) {
            $from->isSameDay($until) && $from->isSameDay($today) => 'today',
            $from->isSameDay($until) && $from->isSameDay($today->addDay()) => 'tomorrow',
            $from->isSameDay($until) => 'on '.$from->format('l j F'),
            default => 'between '.$from->format('j M').' and '.$until->format('j M'),
        };

        $part = $timeOfDay === 'any' ? '' : " in the {$timeOfDay}";

        return "Looking for {$what} {$when}{$part}.";
    }
}
