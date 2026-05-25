<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant is one business: "Bright Lane Studio", "Dr. Weber's practice".
 *
 * Every other business table carries a `tenant_id`. Isolation is enforced by
 * a global scope (App\Models\Concerns\BelongsToTenant), not by remembering to
 * add a where clause — see docs/ARCHITECTURE.md § Multi-tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // IANA identifier, e.g. "Europe/Vienna". The business's own wall
            // clock. Never an offset — offsets break twice a year.
            $table->string('timezone', 64)->default('UTC');

            $table->string('currency', 3)->default('EUR');
            $table->string('locale', 8)->default('en');
            $table->string('contact_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('description')->nullable();

            // Per-tenant overrides of config/slotflow.php booking rules.
            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
