<?php

declare(strict_types=1);

use App\Ai\AiManager;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\Contracts\VerifiesCredentials;
use App\Ai\Credentials\KeyVerification;
use App\Ai\Providers\Provider;
use App\Models\AiProviderCredential;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\StudioFactory;

/**
 * Connecting model providers.
 *
 * The verifier is faked throughout — checking a credential means calling the
 * provider, and this suite makes no network calls and holds no secret. What is
 * under test is everything around that call: who may connect one, that it is
 * verified before it is stored, that keys never come back out, that exactly
 * one provider is in force, and that spend is reported as unknown rather than
 * as zero when nobody has told the application what a model costs.
 */
const ANTHROPIC_KEY = 'sk-ant-api03-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-Ab12';
const OPENAI_KEY = 'sk-proj-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-Cd34';

function fakeVerifier(bool $ok = true, string $error = 'That key was rejected.', ?string $note = null): void
{
    app()->bind(VerifiesCredentials::class, fn () => new class($ok, $error, $note) implements VerifiesCredentials
    {
        public function __construct(private bool $ok, private string $error, private ?string $note) {}

        public function __invoke(Provider $provider, string $apiKey, string $model, ?string $baseUrl = null): KeyVerification
        {
            return $this->ok
                ? KeyVerification::pass($model, $provider->label.' · '.$model, note: $this->note)
                : KeyVerification::fail($this->error);
        }
    });
}

beforeEach(function (): void {
    $this->studio = new StudioFactory;
    $this->owner = $this->studio->owner();

    // The suite forces AI_DRIVER=heuristic so nothing ever calls out. These
    // tests model the shipped default instead: "auto" uses a provider when one
    // resolves. The verifier is still faked, so there is no network call.
    config()->set('ai.driver', 'auto');
    config()->set('ai.platform.api_key', null);

    fakeVerifier();
});

function connect(string $provider, array $overrides = []): array
{
    return [
        'api_key' => $overrides['api_key'] ?? ANTHROPIC_KEY,
        'model' => $overrides['model'] ?? 'claude-opus-5',
        ...$overrides,
    ];
}

describe('access', function (): void {
    it('is owner-only', function (): void {
        $this->getJson('/api/v1/admin/ai-providers', ['X-Tenant' => $this->studio->tenant->slug])
            ->assertUnauthorized();

        Sanctum::actingAs($this->studio->customerUser());
        $this->getJson('/api/v1/admin/ai-providers')->assertForbidden();

        // Staff use every AI feature and read the usage page. They do not get
        // to see or change the credential that pays for it.
        Sanctum::actingAs($this->studio->staffUser());
        $this->getJson('/api/v1/admin/ai-providers')->assertForbidden();
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/ai-providers')->assertOk();
    });

    it('reports an unconfigured workspace without erroring', function (): void {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-providers')
            ->assertOk()
            ->assertJsonCount(0, 'data.connected')
            ->assertJsonPath('data.effective.source', 'none')
            ->assertJsonPath('data.effective.driver', 'heuristic')
            ->assertJsonPath('data.effective.provider', null);
    });

    it('offers the whole catalogue', function (): void {
        Sanctum::actingAs($this->owner);

        $catalogue = $this->getJson('/api/v1/admin/ai-providers')->json('data.catalogue');

        expect(array_column($catalogue, 'id'))->toBe(['anthropic', 'openai', 'deepseek', 'custom']);

        $custom = collect($catalogue)->firstWhere('id', 'custom');
        expect($custom['requires_base_url'])->toBeTrue();

        // DeepSeek has a JSON mode but does not enforce a supplied schema, and
        // the driver behaves differently because of it — so the flag is part
        // of the contract, not a note in a doc.
        expect(collect($catalogue)->firstWhere('id', 'deepseek')['supports_json_schema'])->toBeFalse();
        expect(collect($catalogue)->firstWhere('id', 'anthropic')['supports_json_schema'])->toBeTrue();
    });
});

describe('connecting', function (): void {
    it('verifies before it stores', function (): void {
        fakeVerifier(ok: false, error: 'That key was rejected.');
        Sanctum::actingAs($this->owner);

        $response = $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'));

        $response->assertStatus(422);
        expect($response)->toHaveErrorCode('ai_credential_rejected');

        // Nothing written. A workspace that looks configured but falls back on
        // every call is worse than one that is plainly unconfigured.
        expect(AiProviderCredential::query()->withoutTenantScope()->count())->toBe(0);
    });

    it('connects Anthropic and puts it in force', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))
            ->assertOk()
            ->assertJsonCount(1, 'data.connected')
            ->assertJsonPath('data.connected.0.provider', 'anthropic')
            ->assertJsonPath('data.connected.0.is_active', true)
            ->assertJsonPath('data.connected.0.masked_key', '…Ab12')
            ->assertJsonPath('data.connected.0.tracks_spend', true)
            ->assertJsonPath('data.effective.source', 'workspace')
            ->assertJsonPath('data.effective.driver', 'anthropic');

        app(TenantContext::class)->set($this->studio->tenant);
        expect(app(AiCredentials::class)->resolve()->apiKey)->toBe(ANTHROPIC_KEY);
    });

    it('connects OpenAI', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/openai', [
            'api_key' => OPENAI_KEY,
            'model' => 'gpt-5',
        ])
            ->assertOk()
            ->assertJsonPath('data.effective.provider', 'openai')
            ->assertJsonPath('data.effective.model', 'gpt-5')
            // No published rates for it here, so spend is unknown — not zero.
            ->assertJsonPath('data.connected.0.tracks_spend', false);
    });

    it('connects DeepSeek', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/deepseek', [
            'api_key' => 'sk-deepseek-key-000000000000',
            'model' => 'deepseek-chat',
        ])
            ->assertOk()
            ->assertJsonPath('data.effective.provider', 'deepseek');

        app(TenantContext::class)->set($this->studio->tenant);
        expect(app(AiCredentials::class)->resolve()->baseUrl)->toBe('https://api.deepseek.com/v1');
    });

    it('connects any OpenAI-compatible endpoint', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/custom', [
            'api_key' => 'whatever-this-runtime-wants',
            'model' => 'llama3.1:8b',
            'label' => 'Ollama on the office box',
            'base_url' => 'http://localhost:11434/v1',
            'input_rate_per_mtok' => 0,
            'output_rate_per_mtok' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.connected.0.display_name', 'Ollama on the office box')
            ->assertJsonPath('data.connected.0.endpoint', 'http://localhost:11434/v1')
            // Rates of zero are still rates: a self-hosted model costs nothing
            // per token, and that is a measurement rather than an absence.
            ->assertJsonPath('data.connected.0.tracks_spend', true);
    });

    it('insists a custom endpoint is named and addressed', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/custom', [
            'api_key' => 'a-long-enough-key', 'model' => 'llama3.1:8b',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['label', 'base_url']]]);
    });

    it('refuses to send a bearer token over plain http', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/custom', [
            'api_key' => 'a-long-enough-key', 'model' => 'm', 'label' => 'Somewhere',
            'base_url' => 'http://api.example.com/v1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.base_url.0', fn (string $m) => str_contains($m, 'plain http'));

        // localhost is the exception, because nothing leaves the machine.
        $this->putJson('/api/v1/admin/ai-providers/custom', [
            'api_key' => 'a-long-enough-key', 'model' => 'm', 'label' => 'Local',
            'base_url' => 'http://127.0.0.1:1234/v1',
        ])->assertOk();
    });

    it('rejects an unknown provider', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/some-vendor', connect('x'))->assertStatus(422);
    });

    it('trims a pasted key', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic', [
            'api_key' => '  '.ANTHROPIC_KEY."\n",
        ]))->assertOk();

        expect(AiProviderCredential::query()->withoutTenantScope()->sole()->api_key)->toBe(ANTHROPIC_KEY);
    });

    it('records who connected it', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertOk();

        $credential = AiProviderCredential::query()->withoutTenantScope()->sole();

        expect($credential->key_set_by)->toBe($this->owner->id);
        expect($credential->key_set_at)->not->toBeNull();
    });

    it('passes a verification note through without blocking', function (): void {
        // "The key works, but the provider did not list that model" — common
        // with gateways, and not a reason to refuse a working credential.
        fakeVerifier(note: 'Did not list that model.');
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-providers/openai', ['api_key' => OPENAI_KEY, 'model' => 'gpt-5'])
            ->assertOk()
            ->assertJsonPath('data.verification.note', 'Did not list that model.');
    });
});

describe('exactly one in force', function (): void {
    beforeEach(function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertOk();
        $this->putJson('/api/v1/admin/ai-providers/openai', [
            'api_key' => OPENAI_KEY, 'model' => 'gpt-5', 'make_active' => false,
        ])->assertOk();
    });

    it('keeps the first one active when a second is added passively', function (): void {
        $this->getJson('/api/v1/admin/ai-providers')
            ->assertOk()
            ->assertJsonCount(2, 'data.connected')
            ->assertJsonPath('data.effective.provider', 'anthropic');

        expect(AiProviderCredential::query()->withoutTenantScope()->where('is_active', true)->count())->toBe(1);
    });

    it('switches, demoting the other', function (): void {
        $this->postJson('/api/v1/admin/ai-providers/openai/activate')
            ->assertOk()
            ->assertJsonPath('data.effective.provider', 'openai');

        expect(AiProviderCredential::query()->withoutTenantScope()->where('is_active', true)->count())->toBe(1);
    });

    it('promotes the next one when the active is disconnected', function (): void {
        // Leaving a workspace with keys and none active is a silent downgrade
        // to the fallback.
        $this->deleteJson('/api/v1/admin/ai-providers/anthropic')
            ->assertOk()
            ->assertJsonCount(1, 'data.connected')
            ->assertJsonPath('data.effective.provider', 'openai');
    });

    it('falls all the way back when the last one goes', function (): void {
        $this->deleteJson('/api/v1/admin/ai-providers/anthropic')->assertOk();
        $this->deleteJson('/api/v1/admin/ai-providers/openai')
            ->assertOk()
            ->assertJsonCount(0, 'data.connected')
            ->assertJsonPath('data.effective.source', 'none')
            ->assertJsonPath('data.effective.driver', 'heuristic');
    });
});

describe('secrecy', function (): void {
    it('never returns a key from any endpoint', function (): void {
        Sanctum::actingAs($this->owner);

        $store = $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'));
        $index = $this->getJson('/api/v1/admin/ai-providers');
        $verify = $this->postJson('/api/v1/admin/ai-providers/anthropic/verify');
        $activate = $this->postJson('/api/v1/admin/ai-providers/anthropic/activate');

        foreach ([$store, $index, $verify, $activate] as $response) {
            expect($response->getContent())->not->toContain(ANTHROPIC_KEY);
            // Not even the distinctive middle of it.
            expect($response->getContent())->not->toContain('api03-aaaa');
        }
    });

    it('encrypts the key at rest', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertOk();

        // Straight out of the column, bypassing the cast.
        $raw = DB::table('ai_provider_credentials')->value('api_key');

        expect($raw)->not->toBeNull();
        expect($raw)->not->toContain(ANTHROPIC_KEY);
        expect($raw)->not->toContain('sk-ant');
        expect(AiProviderCredential::query()->withoutTenantScope()->sole()->api_key)->toBe(ANTHROPIC_KEY);
    });

    it('keeps a workspace credential out of another workspace', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertOk();

        app(TenantContext::class)->forget();
        $other = new StudioFactory;

        Sanctum::actingAs($other->owner());
        $this->getJson('/api/v1/admin/ai-providers')
            ->assertOk()
            ->assertJsonCount(0, 'data.connected')
            ->assertJsonPath('data.effective.source', 'none');

        app(TenantContext::class)->set($other->tenant);
        expect(app(AiCredentials::class)->resolve())->toBeNull();
    });
});

describe('spend', function (): void {
    it('tracks cost only when the rates are known', function (): void {
        Sanctum::actingAs($this->owner);
        app(TenantContext::class)->set($this->studio->tenant);

        // No published rates for GPT-5 here, and none supplied.
        $this->putJson('/api/v1/admin/ai-providers/openai', ['api_key' => OPENAI_KEY, 'model' => 'gpt-5'])->assertOk();
        expect(app(AiCredentials::class)->resolve()->tracksSpend())->toBeFalse();

        // Supply them and it starts working.
        $this->putJson('/api/v1/admin/ai-providers/openai', [
            'api_key' => OPENAI_KEY, 'model' => 'gpt-5',
            'input_rate_per_mtok' => 1.25, 'output_rate_per_mtok' => 10,
        ])->assertOk();

        $resolved = app(AiCredentials::class)->resolve();
        expect($resolved->tracksSpend())->toBeTrue();
        expect($resolved->rates)->toBe(['input' => 1.25, 'output' => 10.0]);
    });

    it('lets a workspace set its own ceiling', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => 5.5])
            ->assertOk()
            ->assertJsonPath('data.effective.monthly_budget_usd', 5.5);

        app(TenantContext::class)->set($this->studio->tenant);
        expect(app(AiCredentials::class)->monthlyBudgetUsd())->toBe(5.5);
    });

    it('rejects a negative ceiling', function (): void {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/v1/admin/ai-settings', ['monthly_budget_usd' => -1])->assertStatus(422);
    });
});

describe('lifecycle', function (): void {
    it('re-checks a credential on demand', function (): void {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/admin/ai-providers/anthropic', connect('anthropic'))->assertOk();

        // One that verified on Tuesday can be revoked on Wednesday.
        fakeVerifier(ok: false, error: 'That key was rejected.');

        $this->postJson('/api/v1/admin/ai-providers/anthropic/verify')
            ->assertOk()
            ->assertJsonPath('data.verification.ok', false)
            ->assertJsonPath('data.connected.0.last_check_passed', false)
            ->assertJsonPath('data.connected.0.last_check_error', 'That key was rejected.');
    });

    it('404s on a provider this workspace has not connected', function (): void {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/admin/ai-providers/openai/verify')->assertNotFound();
        $this->deleteJson('/api/v1/admin/ai-providers/openai')->assertNotFound();
    });

    it('falls back to the platform provider when the workspace has none', function (): void {
        config()->set('ai.platform.api_key', 'sk-ant-platform-key-for-everyone-0000');
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/admin/ai-providers')
            ->assertOk()
            ->assertJsonPath('data.effective.source', 'platform')
            ->assertJsonPath('data.effective.provider', 'anthropic');

        // The workspace's own wins once connected…
        $this->putJson('/api/v1/admin/ai-providers/openai', ['api_key' => OPENAI_KEY, 'model' => 'gpt-5'])
            ->assertOk()
            ->assertJsonPath('data.effective.source', 'workspace');

        // …and disconnecting hands it back to the platform.
        $this->deleteJson('/api/v1/admin/ai-providers/openai')
            ->assertOk()
            ->assertJsonPath('data.effective.source', 'platform');
    });
});

it('drives the live flag the AI manager reports', function (): void {
    Sanctum::actingAs($this->owner);
    app(TenantContext::class)->set($this->studio->tenant);

    expect(app(AiManager::class)->isLive())->toBeFalse();

    $this->putJson('/api/v1/admin/ai-providers/deepseek', [
        'api_key' => 'sk-deepseek-000000000000', 'model' => 'deepseek-chat',
    ])->assertOk();

    expect(app(AiManager::class)->isLive())->toBeTrue();
    expect(app(AiManager::class)->activeProvider())->toBe('deepseek');
    expect(app(AiManager::class)->keySource())->toBe('workspace');
});

// Temporary probe: show what the validator actually rejects.
