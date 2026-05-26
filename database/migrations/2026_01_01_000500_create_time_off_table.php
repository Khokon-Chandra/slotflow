<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exceptions that subtract from the weekly rules: holidays, sick days,
 * a dentist appointment on Thursday afternoon.
 *
 * Stored as absolute UTC instants rather than wall-clock times, because a
 * holiday is a real interval on the timeline, not a repeating pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_off', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();

            // Range queries: "any time off overlapping this window".
            $table->index(['tenant_id', 'staff_id', 'starts_at', 'ends_at'], 'time_off_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_off');
    }
};
