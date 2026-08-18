<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One credential per provider, per workspace.
 *
 * Replaces the single `api_key` column on `tenant_ai_settings`, which could
 * only ever hold one vendor's key. A workspace can now connect Anthropic,
 * OpenAI, DeepSeek or any OpenAI-compatible endpoint, and choose which one is
 * in force.
 *
 * ── Why this is a new migration rather than an edit ──────────────────────────
 *
 * The previous table shipped in a commit that people may already have run.
 * Editing an applied migration leaves their database in a state no migration
 * describes, and nothing tells them — the app just starts failing on a column
 * that is missing locally and present in the file. Forward-only, with the data
 * moved rather than dropped, is the boring correct answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Matches a key in config('ai.providers'). Not an enum column:
            // adding a provider is meant to be a config entry, and an enum
            // would make it a migration as well.
            $table->string('provider', 32);

            // Only for the custom provider — a name the owner recognises and
            // the base URL of an OpenAI-compatible endpoint.
            $table->string('label')->nullable();
            $table->string('base_url')->nullable();

            // Encrypted with APP_KEY. Text, not varchar: ciphertext is much
            // larger than the key and a short column truncates silently.
            $table->text('api_key');
            $table->string('key_last_four', 8)->nullable();

            $table->string('model', 96);

            /*
             * Rates in USD per million tokens, as decimals.
             *
             * Nullable because for most providers this application does not
             * know what a model costs, and inventing a number produces a
             * confident, wrong bill estimate that nobody re-checks. Null means
             * spend is untracked and the UI says so.
             */
            $table->decimal('input_rate_per_mtok', 10, 4)->nullable();
            $table->decimal('output_rate_per_mtok', 10, 4)->nullable();

            // Exactly one credential per workspace is in force at a time.
            // Enforced in App\Ai\Credentials\StoreProviderCredential, which
            // demotes the others inside the same transaction.
            $table->boolean('is_active')->default(false);

            $table->timestamp('key_set_at')->nullable();
            $table->foreignId('key_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_error')->nullable();

            $table->timestamps();

            // One credential per provider per workspace. Two rows would mean
            // two keys are "the" key for a provider, and the winner would be
            // whichever the query happened to order first.
            $table->unique(['tenant_id', 'provider']);
            $table->index(['tenant_id', 'is_active']);
        });

        $this->moveExistingAnthropicKeys();

        Schema::table('tenant_ai_settings', function (Blueprint $table): void {
            // The credential now lives in its own table; what remains here is
            // workspace-wide preference.
            $table->dropConstrainedForeignId('key_set_by');
            $table->dropColumn([
                'api_key',
                'key_last_four',
                'key_set_at',
                'verified_at',
                'verification_error',
                'model',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_ai_settings', function (Blueprint $table): void {
            $table->text('api_key')->nullable();
            $table->string('key_last_four', 8)->nullable();
            $table->timestamp('key_set_at')->nullable();
            $table->foreignId('key_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_error')->nullable();
            $table->string('model', 64)->nullable();
        });

        Schema::dropIfExists('ai_provider_credentials');
    }

    /**
     * Carry any key already installed across to the new table.
     *
     * The ciphertext moves verbatim — it was encrypted with the same APP_KEY
     * and the new column has the same cast, so there is nothing to decrypt and
     * therefore nothing to leak into a log or a failed transaction.
     */
    private function moveExistingAnthropicKeys(): void
    {
        if (! Schema::hasColumn('tenant_ai_settings', 'api_key')) {
            return;
        }

        $existing = DB::table('tenant_ai_settings')->whereNotNull('api_key')->get();

        foreach ($existing as $row) {
            DB::table('ai_provider_credentials')->insert([
                'tenant_id' => $row->tenant_id,
                'provider' => 'anthropic',
                'api_key' => $row->api_key,
                'key_last_four' => $row->key_last_four,
                'model' => $row->model ?: config('ai.platform.model', 'claude-opus-5'),
                'input_rate_per_mtok' => 5.0000,
                'output_rate_per_mtok' => 25.0000,
                'is_active' => true,
                'key_set_at' => $row->key_set_at,
                'key_set_by' => $row->key_set_by,
                'verified_at' => $row->verified_at,
                'verification_error' => $row->verification_error,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }
    }
};
