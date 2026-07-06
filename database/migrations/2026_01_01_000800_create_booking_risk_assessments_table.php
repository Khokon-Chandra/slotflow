<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One risk assessment per booking, recomputed when the inputs change.
 *
 * `score`, `band` and `factors` come from a deterministic, unit-tested scorer
 * (App\Domain\Risk\NoShowRiskScorer). `rationale` and `recommended_action` are
 * the only fields a language model writes, and they are advisory text — the
 * number they describe was produced without the model.
 *
 * That split is the whole point: see docs/AI.md § Why the model does not score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_risk_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('score');        // 0–100, deterministic
            $table->string('band', 8);                   // low | medium | high
            $table->json('factors');                     // [{code, label, points}]
            $table->text('rationale')->nullable();       // model-written
            $table->text('recommended_action')->nullable();
            $table->string('generated_by', 32)->default('heuristic'); // driver
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique('booking_id');
            $table->index(['tenant_id', 'band']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_risk_assessments');
    }
};
