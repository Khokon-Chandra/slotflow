<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Short human-quotable code (e.g. "BL-7Q4M2X"). What the customer
            // reads out on the phone; never the primary key.
            $table->string('reference', 16);

            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Always UTC — never a local time, never an offset.
            //
            // `ends_at`      is what the customer is told: start + duration.
            // `blocks_until` is what the diary reserves: end + the service's
            //                buffer, snapshotted at booking time.
            //
            // Two columns rather than one because they answer two different
            // questions. Keeping the buffer out of `ends_at` means the
            // confirmation email is honest; keeping it *in* a stored column
            // means the double-booking check is one indexed SQL predicate
            // instead of a PHP-side reconciliation the database cannot enforce.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('blocks_until');

            $table->string('status', 16)->default('pending');
            $table->string('source', 16)->default('web');

            // The timezone the customer booked in, so confirmations and
            // reminders render in the clock they actually used.
            $table->string('customer_timezone', 64)->default('UTC');

            // Snapshot: a later price change must not rewrite history.
            $table->unsignedInteger('price_cents')->default(0);

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);

            // The overlap check inside BookingService::create() locks on this
            // index. Column order matters: staff first (equality), then the
            // time range (range scan).
            $table->index(['tenant_id', 'staff_id', 'starts_at'], 'bookings_diary_index');

            // Admin list view: filter by status, order by time.
            $table->index(['tenant_id', 'status', 'starts_at'], 'bookings_status_index');

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
