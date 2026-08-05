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

describe('service copy', function (): void {
    it('returns a draft and does not save it', function (): void {
        Sanctum::actingAs($this->studio->owner());

        $response = $this->postJson('/api/v1/ai/service-description', [
            'name' => 'Hot towel shave',
            'duration_minutes' => 30,
            'price_cents' => 2800,
        ]);

        $response->assertOk()->assertJsonStructure(['data' => ['description', 'highlights', 'ai']]);

        // A draft for a human to edit — nothing was written.
        expect(App\Models\Service::query()->where('name', 'Hot towel shave')->exists())->toBeFalse();
    });
});

describe('observability', function (): void {
    it('logs every call with its cost and latency', function (): void {
        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow',
            'tz' => 'Europe/Vienna',
        ], $this->headers)->assertOk();

        $row = AiInteraction::query()->withoutTenantScope()->latest('id')->sole();

        expect($row->task)->toBe(AiTask::BookingIntent);
        expect($row->driver)->toBe('heuristic');
        expect($row->succeeded)->toBeTrue();
        expect($row->tenant_id)->toBe($this->studio->tenant->id);
    });

    it('reports usage to the business', function (): void {
        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow',
            'tz' => 'Europe/Vienna',
        ], $this->headers);

        Sanctum::actingAs($this->studio->owner());

        $this->getJson('/api/v1/admin/ai-usage')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['window_days', 'total_cost_usd', 'monthly_budget_usd', 'by_task'],
            ]);
    });
});

describe('degradation', function (): void {
    it('serves an answer when the AI client throws', function (): void {
        // Swap in a client that fails the way a real outage does.
        $this->app->bind(App\Ai\Drivers\ClaudeClient::class, fn () => new class implements AiClient
        {
            public function name(): string
            {
                return 'claude';
            }

            public function run(AiRequest $request): AiResponse
            {
                throw new RuntimeException('API is down');
            }
        });

        config()->set('ai.driver', 'claude');

        $response = $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow morning',
            'tz' => 'Europe/Vienna',
        ], $this->headers);

        // The customer still gets slots. They are told the answer was degraded.
        $response->assertOk()
            ->assertJsonPath('data.ai.driver', 'heuristic')
            ->assertJsonPath('data.ai.degraded_reason', 'api_error');

        expect($response->json('data.slots'))->not->toBeEmpty();

        // And the failure is on the record.
        expect(AiInteraction::query()->withoutTenantScope()->latest('id')->sole()->succeeded)->toBeFalse();
    });

    it('stops calling the API once the monthly budget is spent', function (): void {
        config()->set('ai.driver', 'claude');
        config()->set('ai.monthly_budget_usd', 0.01);

        AiInteraction::query()->create([
            'tenant_id' => $this->studio->tenant->id,
            'task' => AiTask::BookingIntent,
            'driver' => 'claude',
            'model' => 'claude-opus-5',
            'cost_micros' => 50_000,          // $0.05, over the ceiling
            'succeeded' => true,
        ]);

        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow',
            'tz' => 'Europe/Vienna',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.ai.degraded_reason', 'monthly_budget_reached');
    });

    it('falls back when no API key is configured', function (): void {
        config()->set('ai.driver', 'auto');
        config()->set('ai.claude.api_key', null);

        $this->postJson('/api/v1/ai/booking-assistant', [
            'text' => 'a haircut tomorrow',
            'tz' => 'Europe/Vienna',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.ai.degraded_reason', 'no_api_key');
    });
});
