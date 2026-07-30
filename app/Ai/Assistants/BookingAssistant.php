<?php

declare(strict_types=1);

namespace App\Ai\Assistants;

use App\Ai\Results\BookingIntent;
use App\Ai\Tasks\ParseBookingRequest;
use App\Domain\Availability\AvailabilityEngine;
use App\Domain\Availability\AvailabilityQuery;
use App\Domain\Availability\Slot;
use App\Models\Service;
use App\Models\Tenant;

/**
 * The end-to-end "just tell me what you need" flow.
 *
 *   free text → intent (model) → real slots (database) → suggestions
 *
 * The important line in this class is the one that does *not* exist: nothing
 * here writes a booking. The model narrows a search; the availability engine
 * answers it from the diary; the customer chooses. If the model
 * misunderstands, the worst outcome is slots the customer does not want,
 * which they can see and ignore — not an appointment they did not make.
 */
final class BookingAssistant
{
    public function __construct(
        private readonly ParseBookingRequest $parser,
        private readonly AvailabilityEngine $availability,
    ) {}

    /**
     * @return array{
     *     intent: array<string, mixed>,
     *     service: array<string, mixed>|null,
     *     slots: list<array<string, mixed>>,
     *     relaxed: bool,
     *     message: string,
     *     ai: array<string, mixed>
     * }
     */
    public function suggest(Tenant $tenant, string $text, string $timezone, int $limit = 6): array
    {
        ['intent' => $intent, 'response' => $response] = ($this->parser)($tenant, $text, $timezone);

        if ($intent->needsService()) {
            return [
                'intent' => $intent->toArray(),
                'service' => null,
                'slots' => [],
                'relaxed' => false,
                'message' => $intent->clarification ?? 'Which service would you like to book?',
                'ai' => $response->provenance(),
            ];
        }

        $service = Service::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->find($intent->serviceId);

        if ($service === null) {
            // The model returned an id that is not bookable. Do not guess a
            // replacement — say so.
            return [
                'intent' => $intent->toArray(),
                'service' => null,
                'slots' => [],
                'relaxed' => false,
                'message' => 'That service is not available for online booking. Please pick one from the list.',
                'ai' => $response->provenance(),
            ];
        }

        [$slots, $relaxed] = $this->findSlots($service, $intent, $timezone, $limit);

        return [
            'intent' => $intent->toArray(),
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'duration_minutes' => $service->duration_minutes,
                'price_cents' => $service->price_cents,
            ],
            'slots' => array_map(fn (Slot $slot) => $slot->toArray(), $slots),
            'relaxed' => $relaxed,
            'message' => $this->message($intent, $slots, $relaxed),
            'ai' => $response->provenance(),
        ];
    }

    /**
     * Try the customer's stated preference first. If the diary has nothing in
     * that window, widen once — a customer who asked for Tuesday afternoon
     * would rather see Tuesday morning than an empty page, as long as they are
     * told that is what happened.
     *
     * @return array{0: list<Slot>, 1: bool}
     */
    private function findSlots(Service $service, BookingIntent $intent, string $timezone, int $limit): array
    {
        $query = new AvailabilityQuery(
            service: $service,
            fromDate: $intent->dateFrom,
            untilDate: $intent->dateUntil,
            timezone: $timezone,
            staffId: $intent->staffId,
        );

        $all = $this->availability->find($query);

        $preferred = array_values(array_filter(
            $all,
            fn (Slot $slot) => $intent->matchesTime($slot->localStart()),
        ));

        if ($preferred !== []) {
            return [array_slice($preferred, 0, $limit), false];
        }

        return [array_slice($all, 0, $limit), $all !== []];
    }

    /**
     * @param  list<Slot>  $slots
     */
    private function message(BookingIntent $intent, array $slots, bool $relaxed): string
    {
        if ($slots === []) {
            return 'Nothing free in that window, I am afraid. Try a different day or a wider range.';
        }

        if ($relaxed) {
            $part = $intent->timeOfDay === 'any' ? 'that window' : "the {$intent->timeOfDay}";

            return "Nothing free in {$part}, but here is what is open nearby.";
        }

        return $intent->summary;
    }
}
