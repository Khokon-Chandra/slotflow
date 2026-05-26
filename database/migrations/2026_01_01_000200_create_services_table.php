<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Comma-separated search terms the owner controls: "haircut, trim,
            // blow dry". Customers do not type your menu names at you — they
            // ask for a haircut, and the service is called "Cut & finish".
            // Without this the matcher is guessing from the name alone.
            $table->string('keywords', 500)->nullable();

            // Duration the customer occupies. Buffer is cleanup/turnaround
            // time after the appointment: it blocks the diary but is not shown
            // to the customer and is not billed.
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);

            // Money is stored as integer minor units. Never floats.
            $table->unsignedInteger('price_cents')->default(0);

            $table->string('color', 7)->default('#6366f1');
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_deposit')->default(false);
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
