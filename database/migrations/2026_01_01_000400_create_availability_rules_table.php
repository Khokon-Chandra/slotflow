<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Availability is stored as *rules*, not as pre-generated slot rows.
 *
 * One row = "Maya works Tuesdays 09:00–13:00, in her own timezone".
 * Free slots are computed at request time from
 *   (rules − time off − existing bookings).
 *
 * The alternative — a `slots` table with a row per bookable 15 minutes —
 * is what most booking schemas do and it breaks the first time someone
 * changes their hours or a service length changes. See docs/AVAILABILITY.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            // 0 = Sunday … 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('weekday');

            // Wall-clock times in the *staff member's* timezone.
            $table->time('starts_at');
            $table->time('ends_at');

            // Lets a rule be scheduled ("from March she works Fridays too")
            // and retired without deleting history.
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestamps();

            // The availability engine's hot path: every slot lookup filters by
            // staff and weekday.
            $table->index(['tenant_id', 'staff_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_rules');
    }
};
