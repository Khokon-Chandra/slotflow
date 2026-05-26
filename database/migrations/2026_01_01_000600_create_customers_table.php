<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer record is separate from `users`: most people who book never
 * create an account, and the business still needs their history.
 *
 * The three counters are denormalised on purpose — the risk scorer reads them
 * on every booking write, and a COUNT over the bookings table on each call is
 * the classic N+1 that shows up six months in. They are maintained inside the
 * same transaction that changes a booking's status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->text('notes')->nullable();

            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
