<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace AI credentials and limits.
 *
 * Each business brings its own Anthropic key and pays its own bill, which is
 * how this works in every SaaS that resells model access. The platform key in
 * .env remains a fallback, so a single-tenant deployment needs none of this.
 *
 * The key is encrypted at rest with APP_KEY (Eloquent's `encrypted` cast) and
 * is never returned by any endpoint — only the last four characters, which is
 * enough for a human to recognise which key is installed and useless to anyone
 * who obtains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_settings', function (Blueprint $table): void {
            $table->id();

            // One row per workspace. A unique constraint rather than a check
            // in application code, because two rows here would mean two
            // different keys are "the" key and the winner is whichever the
            // query happened to order first.
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Encrypted. Long, because ciphertext is much larger than the
            // plaintext key and a varchar(255) would silently truncate it.
            $table->text('api_key')->nullable();

            // Stored separately so the settings page can show which key is
            // installed without decrypting anything.
            $table->string('key_last_four', 8)->nullable();

            $table->timestamp('key_set_at')->nullable();
            $table->foreignId('key_set_by')->nullable()->constrained('users')->nullOnDelete();

            // The result of the last check against the Anthropic API. A key
            // that verified at save time can still be revoked later, so this
            // records when it was last known good rather than asserting it is.
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_error')->nullable();

            // Null means "use the platform default from config/ai.php".
            $table->string('model', 64)->nullable();
            $table->decimal('monthly_budget_usd', 8, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_settings');
    }
};
