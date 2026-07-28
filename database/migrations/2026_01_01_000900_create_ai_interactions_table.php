<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every AI call is logged: which task, which driver, how many tokens, how
 * long, what it cost, whether it failed.
 *
 * Without this an "AI feature" is a black box you cannot budget for, debug or
 * explain to a client. The admin AI panel and the monthly spend guard both
 * read this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('task', 32);
            $table->string('driver', 16);              // claude | heuristic
            $table->string('model', 64)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);

            // Cost in micro-dollars (1e-6 USD) — integer, so summing a month
            // of rows never drifts.
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('succeeded')->default(true);
            $table->boolean('served_from_cache')->default(false);
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'task', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
