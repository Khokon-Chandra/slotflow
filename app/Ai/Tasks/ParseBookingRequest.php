<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Ai\Results\BookingIntent;
use App\Enums\AiTask;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Turns "can I get a trim next Tuesday afternoon?" into a structured search.
 *
 * The prompt gives the model the tenant's real services and staff with their
 * real IDs, and the schema restricts it to returning one of them or null.
 * That is the difference between an assistant and a liability: it cannot
 * invent a service the business does not offer, because there is nowhere in
 * the output shape to put one.
 */
final class ParseBookingRequest
{
    public function __construct(private readonly AiClient $ai) {}

    /**
     * @return array{intent: BookingIntent, response: AiResponse}
     */
    public function __invoke(Tenant $tenant, string $text, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $services = Service::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->ordered()
            ->get(['id', 'name', 'description', 'keywords', 'duration_minutes', 'price_cents']);

        $staff = Staff::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->ordered()
            ->get(['id', 'name', 'title']);

        $payload = [
            'text' => $text,
            'timezone' => $timezone,
            'today' => $today->toDateString(),
            'services' => $services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'keywords' => $s->keywords,
                'duration_minutes' => $s->duration_minutes,
                'price_cents' => $s->price_cents,
            ])->all(),
            'staff' => $staff->map(fn (Staff $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'title' => $m->title,
            ])->all(),
        ];

        $response = $this->ai->run(new AiRequest(
            task: AiTask::BookingIntent,
            system: $this->system($tenant, $payload, $today),
            prompt: $text,
            schema: self::schema(),
            payload: $payload,
            // Two customers asking the same thing on the same day get the same
            // parse; there is no reason to pay for it twice.
            cacheKey: hash('xxh128', $tenant->id.'|'.$today->toDateString().'|'.$timezone.'|'.mb_strtolower(trim($text))),
            maxTokens: 900,
        ));

        return [
            'intent' => BookingIntent::fromArray($response->data, $timezone, $today),
            'response' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function system(Tenant $tenant, array $payload, CarbonImmutable $today): string
    {
        $services = collect($payload['services'])
            ->map(fn (array $s) => sprintf(
                '  - id %d: "%s" (%d min%s)%s',
                $s['id'],
                $s['name'],
                $s['duration_minutes'],
                $s['price_cents'] > 0 ? sprintf(', %s %s', number_format($s['price_cents'] / 100, 2), $tenant->currency) : '',
                filled($s['description']) ? ' — '.mb_substr((string) $s['description'], 0, 120) : '',
            ).(filled($s['keywords']) ? "\n      also asked for as: {$s['keywords']}" : ''))
            ->implode("\n");

        $staff = collect($payload['staff'])
            ->map(fn (array $m) => sprintf('  - id %d: %s%s', $m['id'], $m['name'], filled($m['title']) ? ", {$m['title']}" : ''))
            ->implode("\n");

        return <<<PROMPT
        You extract booking intent for {$tenant->name}, a business that takes appointments.

        Today is {$today->format('l, j F Y')} in the customer's timezone ({$payload['timezone']}).

        Services offered:
        {$services}

        Team members:
        {$staff}

        Rules:
        - Only ever return an id from the lists above. If the request does not
          clearly match one service, return null for service_id and put a short,
          friendly question in `clarification`.
        - Resolve relative dates against today's date given above. "Next Tuesday"
          means the Tuesday of next week; a bare weekday means the next one to
          come round.
        - When no date is mentioned, search the coming seven days.
        - Prefer a wider date window over a wrong one. The customer will be shown
          real available slots afterwards and can pick from them.
        - `summary` is one sentence, addressed to the customer, confirming what
          you understood. No greeting, no sign-off.
        - Never promise a time, a price or that a slot is available. You are
          reading the request, not answering it.
        PROMPT;
    }

    /**
     * The output contract. `additionalProperties: false` plus a complete
     * `required` list is what makes the response safe to consume without
     * defensive checks at every call site.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'service_id', 'confidence', 'staff_id', 'date_from', 'date_until',
                'time_of_day', 'earliest_time', 'latest_time', 'summary', 'clarification',
            ],
            'properties' => [
                'service_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'The id of the matched service, or null if unclear.',
                ],
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['high', 'medium', 'low'],
                    'description' => 'How sure you are about the service match.',
                ],
                'staff_id' => [
                    'type' => ['integer', 'null'],
                    'description' => 'The id of a specifically requested team member, or null.',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'First date to search, YYYY-MM-DD, in the customer timezone.',
                ],
                'date_until' => [
                    'type' => 'string',
                    'description' => 'Last date to search, YYYY-MM-DD, inclusive.',
                ],
                'time_of_day' => [
                    'type' => 'string',
                    'enum' => ['morning', 'afternoon', 'evening', 'any'],
                ],
                'earliest_time' => [
                    'type' => ['string', 'null'],
                    'description' => 'Earliest acceptable local start time as HH:MM, or null.',
                ],
                'latest_time' => [
                    'type' => ['string', 'null'],
                    'description' => 'Latest acceptable local start time as HH:MM, or null.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One sentence confirming what the customer asked for.',
                ],
                'clarification' => [
                    'type' => ['string', 'null'],
                    'description' => 'A short question to ask when the request is ambiguous, else null.',
                ],
            ],
        ];
    }
}
