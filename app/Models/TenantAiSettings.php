<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Workspace-wide AI preferences.
 *
 * Credentials used to live here too. They now live in
 * `ai_provider_credentials`, one row per provider, because a single `api_key`
 * column could only ever hold one vendor's key. What remains is the settings
 * that are about the workspace rather than about a particular provider.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $monthly_budget_usd
 */
#[Table('tenant_ai_settings')]
#[Fillable(['tenant_id', 'monthly_budget_usd'])]
class TenantAiSettings extends Model
{
    use BelongsToTenant;
}
