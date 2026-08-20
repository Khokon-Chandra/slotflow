<?php

declare(strict_types=1);

use App\Ai\Credentials\Contracts\VerifiesCredentials;
use App\Ai\Credentials\KeyVerification;
use App\Ai\Providers\Provider;
use App\Models\AiProviderCredential;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StudioFactory;

/**
 * The AI providers page.
 *
 * Its own screen, and its own menu entry, because connecting a provider is a
 * credential-management job done rarely by one person — nothing to do with
 * reading yesterday's token spend.
 */
beforeEach(function (): void {
    $this->studio = new StudioFactory;
    config()->set('slotflow.demo.tenant_slug', $this->studio->tenant->slug);

    app()->bind(VerifiesCredentials::class, fn () => new class implements VerifiesCredentials
    {
        public function __invoke(Provider $provider, string $apiKey, string $model, ?string $baseUrl = null): KeyVerification
        {
            return KeyVerification::pass($model, $provider->label);
        }
    });
});

it('is owner-only', function (): void {
    $this->actingAs($this->studio->staffUser())->get('/admin/ai-providers')->assertForbidden();
    $this->actingAs($this->studio->owner())->get('/admin/ai-providers')->assertOk();
});

it('hands the page plain arrays, not wrapped resources', function (): void {
    /*
     * A JSON response unwraps a nested resource collection; Inertia does not.
     * Without ->resolve() the page receives { data: [...] } where it expects
     * an array, and v-for iterates the wrapper's single key — rendering one
     * row that does not exist. It looks fine while the list is empty, which is
     * exactly when nobody checks.
     */
    $credential = new AiProviderCredential;
    $credential->tenant_id = $this->studio->tenant->id;
    $credential->provider = 'anthropic';
    $credential->model = 'claude-opus-5';
    // Assigned, not mass-assigned: `api_key` and `is_active` are absent from
    // Fillable on purpose, so `create()` would drop them.
    $credential->api_key = 'sk-ant-test-000000000000';
    $credential->key_last_four = '0000';
    $credential->is_active = true;
    $credential->save();

    $this->actingAs($this->studio->owner())
        ->get('/admin/ai-providers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AiProviders')
            ->has('connected', 1)
            ->has('connected.0.provider')
            ->has('catalogue', 4)
            ->where('effective.provider', 'anthropic')
        );
});

it('shows an empty state without a connected provider', function (): void {
    $this->actingAs($this->studio->owner())
        ->get('/admin/ai-providers')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AiProviders')
            ->has('connected', 0)
            ->where('effective.source', 'none')
            ->where('effective.driver', 'heuristic')
        );
});

it('keeps credentials off the usage page', function (): void {
    // Telemetry and credentials are separate screens now. The usage page must
    // not still be leaking the credential props it used to carry.
    $this->actingAs($this->studio->owner())
        ->get('/admin/ai')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/AiUsage')
            ->missing('aiSettings')
            ->missing('aiEffective')
            ->has('canManageCredentials')
        );
});
