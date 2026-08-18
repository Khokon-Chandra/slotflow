<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit row for one AI call. See docs/AI.md § Observability.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property AiTask $task
 * @property string $driver
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cached_input_tokens
 * @property int $cost_micros
 * @property int $latency_ms
 * @property bool $succeeded
 * @property bool $served_from_cache
 * @property string|null $failure_reason
 * @property \Carbon\CarbonImmutable|null $created_at
 */
#[Fillable([
    'tenant_id', 'user_id', 'task', 'driver', 'model',
    'input_tokens', 'output_tokens', 'cached_input_tokens',
    'cost_micros', 'latency_ms', 'succeeded', 'served_from_cache', 'failure_reason',
])]
class AiInteraction extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cost_micros' => 'integer',
            'latency_ms' => 'integer',
            'succeeded' => 'boolean',
            'served_from_cache' => 'boolean',
        ];
    }

    public function costUsd(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    #[Scope]
    protected function thisMonth(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }

    #[Scope]
    protected function billable(Builder $query): Builder
    {
        // Anything that was not the built-in fallback and was not served from
        // cache actually cost money — whichever provider answered.
        return $query->where('driver', '!=', 'heuristic')->where('served_from_cache', false);
    }
}
