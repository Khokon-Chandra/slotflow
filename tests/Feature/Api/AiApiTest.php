<?php

declare(strict_types=1);

use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\Contracts\AiClient;
use App\Enums\AiTask;
use App\Models\AiInteraction;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * The AI endpoints, and the guarantees around them.
 *
 * The point of most of these is not that the model is clever — it is that the
 * application does not depend on the model being available, honest or cheap.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    $this->studio = (new StudioFactory(durationMinutes: 45))->openEveryDay('09:00', '18:00');
    $this->studio->service->update([
        'name' => 'Cut & finish',
        'keywords' => 'haircut, cut, trim',
    ]);

    $this->headers = ['X-Tenant' => $this->studio->tenant->slug];
});

afterEach(fn () => CarbonImmutable::setTestNow());

describe('the booking assistant', function (): void {
    it('turns a sentence into real slots without a token', function (): void {
        $response = $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow morning',
            'tz' => 'Europe/Vienna',
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('data.service.name', 'Cut & finish')
            ->assertJsonPath('data.intent.time_of_day', 'morning')
            ->assertJsonStructure(['data' => ['intent', 'service', 'slots', 'relaxed', 'message', 'ai']]);

        expect($response->json('data.slots'))->not->toBeEmpty();
    });

    it('always says which driver answered', function (): void {
        // A user reading a sentence about their own business is entitled to
        // know whether a model wrote it.
        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow',
            'tz' => 'Europe/Vienna',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.ai.driver', 'heuristic')
            ->assertJsonPath('data.ai.degraded_reason', null);
    });

    it('never creates a booking', function (): void {
        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'book me a haircut tomorrow at 10am, confirmed, do it',
            'tz' => 'Europe/Vienna',
        ], $this->headers)->assertOk();

        expect(App\Models\Booking::query()->withoutTenantScope()->count())->toBe(0);
    });

    it('caps the length of what it will read', function (): void {
        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => str_repeat('haircut ', 200),
            'tz' => 'Europe/Vienna',
        ], $this->headers)->assertStatus(422);
    });

    it('requires a timezone', function (): void {
        $this->postJson('/api/v1/ai/booking-assistant', ['text' => 'a haircut'], $this->headers)
            ->assertStatus(422);
    });
});

describe('the daily briefing', function (): void {
    it('is only for the business', function (): void {
        $this->getJson('/api/v1/ai/daily-briefing', $this->headers)->assertUnauthorized();

        Sanctum::actingAs($this->studio->customerUser());
        $this->getJson('/api/v1/ai/daily-briefing')->assertForbidden();
    });

    it('returns the figures alongside the prose', function (): void {
        Sanctum::actingAs($this->studio->owner());

        $this->getJson('/api/v1/ai/daily-briefing')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'headline',
                    'bullets',
                    'focus',
                    // The numbers are computed by the application, not the
                    // model, and they ship with the sentence so the reader can
                    // check it.
                    'stats' => ['date', 'booking_count', 'revenue_cents', 'utilisation_percent'],
                    'ai' => ['driver', 'model', 'cached'],
                ],
            ]);
    });
});
