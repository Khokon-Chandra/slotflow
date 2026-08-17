<?php

declare(strict_types=1);

use App\Ai\AiManager;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\Contracts\VerifiesApiKeys;
use App\Ai\Credentials\KeyVerification;
use App\Models\TenantAiSettings;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * Per-workspace AI credentials.
 *
 * The verifier is faked throughout — checking a key means calling Anthropic,
 * and this suite makes no network calls and holds no secret. What is under
 * test is everything around that call: who may set a key, that it is verified
 * before it is stored, that it never comes back out, and that it takes
 * precedence over the platform key without leaking to another workspace.
 */
const VALID_KEY = 'sk-ant-api03-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-Ab12';

function fakeVerifier(bool $ok, string $error = 'That key was rejected.'): void
{
    app()->bind(VerifiesApiKeys::class, fn () => new class($ok, $error) implements VerifiesApiKeys
    {
        public function __construct(private bool $ok, private string $error) {}

        public function __invoke(string $apiKey, string $model): KeyVerification
        {
            return $this->ok
                ? KeyVerification::pass($model, 'Claude Opus 5', 1_000_000)
                : KeyVerification::fail($this->error);
        }
    });
}

beforeEach(function (): void {
    $this->studio = new StudioFactory;
    $this->owner = $this->studio->owner();

    // The suite forces AI_DRIVER=heuristic so nothing ever calls out. These
    // tests are about credentials, so they model the shipped default instead:
    // "auto" means Claude when a key resolves, heuristic when none does. The
    // verifier is still faked, so there is no network call either way.
    config()->set('ai.driver', 'auto');
    config()->set('ai.claude.api_key', null);

    fakeVerifier(ok: true);
});

describe('access', function (): void {
    it('is owner-only', function (): void {
        $this->getJson('/api/v1/admin/ai-settings', ['X-Tenant' => $this->studio->tenant->slug])
            ->assertUnauthorized();

        Sanctum::actingAs($this->studio->customerUser());
        $this->getJson('/api/v1/admin/ai-settings')->assertForbidden();

        // Staff use every AI feature and read the usage page. They do not get
        // to see or change the credential that pays for it.
        Sanctum::actingAs($this->studio->staffUser());
        $this->getJson('/api/v1/admin/ai-settings')->assertForbidden();
        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/ai-settings')->assertOk();
    });

    it('reports an unconfigured workspace without erroring', function (): void {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.has_key', false)
            ->assertJsonPath('data.settings.masked_key', null)
            ->assertJsonPath('data.effective.key_source', 'none')
            ->assertJsonPath('data.effective.driver', 'heuristic');
    });
});

describe('installing a key', function (): void {
    it('verifies before it stores', function (): void {
        fakeVerifier(ok: false, error: 'That key was rejected.');
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY]);

        $response->assertStatus(422);
        expect($response)->toHaveErrorCode('ai_key_rejected');

        // Nothing written. A workspace that looks configured but falls back on
        // every call is worse than one that is plainly unconfigured.
        expect(TenantAiSettings::query()->withoutTenantScope()->count())->toBe(0);
    });

    it('stores a verified key and puts it in force', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])
            ->assertOk()
            ->assertJsonPath('data.settings.has_key', true)
            ->assertJsonPath('data.settings.masked_key', 'sk-ant-…Ab12')
            ->assertJsonPath('data.settings.last_check_passed', true)
            ->assertJsonPath('data.effective.key_source', 'tenant')
            ->assertJsonPath('data.effective.driver', 'claude');

        app(TenantContext::class)->set($this->studio->tenant);
        expect(app(AiCredentials::class)->apiKey())->toBe(VALID_KEY);
    });

    it('records who installed it and when', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertOk();

        $settings = TenantAiSettings::query()->withoutTenantScope()->sole();

        expect($settings->key_set_by)->toBe($this->owner->id);
        expect($settings->key_set_at)->not->toBeNull();
    });

    it('trims a pasted key', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => '  '.VALID_KEY."\n"])
            ->assertOk();

        expect(TenantAiSettings::query()->withoutTenantScope()->sole()->api_key)->toBe(VALID_KEY);
    });

    it('rejects a key with whitespace in the middle', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => 'sk-ant-api03 broken key here'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.api_key.0', fn (string $m) => str_contains($m, 'spaces'));
    });

    it('only accepts a model it can price', function (): void {
        Sanctum::actingAs($this->owner);

        // Otherwise cost tracking silently reports zero, which is worse than
        // refusing the model.
        $this->putJson('/api/v1/admin/ai-settings/key', [
            'api_key' => VALID_KEY,
            'model' => 'some-model-we-cannot-price',
        ])->assertStatus(422);

        $this->putJson('/api/v1/admin/ai-settings/key', [
            'api_key' => VALID_KEY,
            'model' => 'claude-haiku-4-5',
        ])->assertOk()->assertJsonPath('data.effective.model', 'claude-haiku-4-5');
    });
});

describe('secrecy', function (): void {
    it('never returns the key from any endpoint', function (): void {
        Sanctum::actingAs($this->owner);

        $store = $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY]);
        $show = $this->getJson('/api/v1/admin/ai-settings');
        $verify = $this->postJson('/api/v1/admin/ai-settings/verify');
        $update = $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => 10]);

        foreach ([$store, $show, $verify, $update] as $response) {
            expect($response->getContent())->not->toContain(VALID_KEY);
            // Not even the distinctive middle of it.
            expect($response->getContent())->not->toContain('api03-aaaa');
        }
    });

    it('encrypts the key at rest', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertOk();

        // Straight out of the column, bypassing the cast.
        $raw = DB::table('tenant_ai_settings')->value('api_key');

        expect($raw)->not->toBeNull();
        expect($raw)->not->toContain(VALID_KEY);
        expect($raw)->not->toContain('sk-ant');
        expect(TenantAiSettings::query()->withoutTenantScope()->sole()->api_key)->toBe(VALID_KEY);
    });

    it('keeps a workspace key out of another workspace', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertOk();

        app(TenantContext::class)->forget();
        $other = new StudioFactory;

        Sanctum::actingAs($other->owner());
        $this->getJson('/api/v1/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.has_key', false)
            ->assertJsonPath('data.effective.key_source', 'none');

        app(TenantContext::class)->set($other->tenant);
        expect(app(AiCredentials::class)->apiKey())->toBeNull();
    });
});

describe('lifecycle', function (): void {
    it('re-checks a stored key on demand', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertOk();

        // A key that verified on Tuesday can be revoked on Wednesday.
        fakeVerifier(ok: false, error: 'That key was rejected.');

        $this->postJson('/api/v1/admin/ai-settings/verify')
            ->assertOk()
            ->assertJsonPath('data.verification.ok', false)
            ->assertJsonPath('data.settings.last_check_passed', false)
            ->assertJsonPath('data.settings.last_check_error', 'That key was rejected.');
    });

    it('refuses to check a key that is not there', function (): void {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/admin/ai-settings/verify');

        $response->assertStatus(422);
        expect($response)->toHaveErrorCode('no_key_installed');
    });

    it('removes the key but keeps the preferences', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings/key', [
            'api_key' => VALID_KEY,
            'model' => 'claude-haiku-4-5',
        ])->assertOk();
        $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => 40])->assertOk();

        $this->deleteJson('/api/v1/admin/ai-settings/key')
            ->assertOk()
            ->assertJsonPath('data.settings.has_key', false)
            ->assertJsonPath('data.effective.key_source', 'none')
            // Putting a key back should not mean reconfiguring everything else.
            ->assertJsonPath('data.settings.model', 'claude-haiku-4-5')
            ->assertJsonPath('data.settings.monthly_budget_usd', 40);
    });

    it('falls back to the platform key when the workspace has none', function (): void {
        config()->set('ai.claude.api_key', 'sk-ant-platform-key-for-everyone-0000');
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.effective.key_source', 'platform')
            ->assertJsonPath('data.effective.driver', 'claude');

        // The workspace's own key wins once installed…
        $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])
            ->assertOk()
            ->assertJsonPath('data.effective.key_source', 'tenant');

        // …and removing it hands the workspace back to the platform.
        $this->deleteJson('/api/v1/admin/ai-settings/key')
            ->assertOk()
            ->assertJsonPath('data.effective.key_source', 'platform');
    });
});

describe('budget', function (): void {
    it('lets a workspace set its own ceiling', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => 5.5])
            ->assertOk()
            ->assertJsonPath('data.effective.monthly_budget_usd', 5.5);

        app(TenantContext::class)->set($this->studio->tenant);
        expect(app(AiCredentials::class)->monthlyBudgetUsd())->toBe(5.5);
    });

    it('falls back to the platform ceiling when unset', function (): void {
        config()->set('ai.monthly_budget_usd', 25);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.effective.monthly_budget_usd', 25);
    });

    it('rejects a negative ceiling', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => -1])
            ->assertStatus(422);
    });
});

it('offers only models it can price', function (): void {
    Sanctum::actingAs($this->owner);

    $models = $this->getJson('/api/v1/admin/ai-settings')->json('data.available_models');

    expect($models)->not->toBeEmpty();
    expect(array_column($models, 'id'))->toBe(array_keys((array) config('ai.pricing')));

    foreach ($models as $model) {
        expect($model)->toHaveKeys(['id', 'input_per_mtok_usd', 'output_per_mtok_usd', 'is_platform_default']);
    }
});

it('drives the live flag the AI manager reports', function (): void {
    Sanctum::actingAs($this->owner);
    app(TenantContext::class)->set($this->studio->tenant);

    expect(app(AiManager::class)->isLive())->toBeFalse();

    $this->putJson('/api/v1/admin/ai-settings/key', ['api_key' => VALID_KEY])->assertOk();

    expect(app(AiManager::class)->isLive())->toBeTrue();
    expect(app(AiManager::class)->keySource())->toBe('tenant');
});
