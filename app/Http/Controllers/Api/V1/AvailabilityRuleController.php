<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Availability\AvailabilityEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncAvailabilityRulesRequest;
use App\Http\Resources\AvailabilityRuleResource;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Working hours
 *
 * A staff member's weekly pattern, in their own timezone.
 */
final class AvailabilityRuleController extends Controller
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * List working hours
     */
    public function index(Request $request, Staff $staff): AnonymousResourceCollection
    {
        $this->authorize('view', $staff);

        return AvailabilityRuleResource::collection(
            $staff->availabilityRules()->orderBy('weekday')->orderBy('starts_at')->get()
        );
    }

    /**
     * Replace working hours
     *
     * Send the complete week. The operation is a replace, not a merge, so it
     * is idempotent — sending the same payload twice leaves the same schedule.
     */
    public function sync(SyncAvailabilityRulesRequest $request, Staff $staff): AnonymousResourceCollection
    {
        DB::transaction(function () use ($request, $staff): void {
            $staff->availabilityRules()->delete();

            foreach ($request->input('rules', []) as $rule) {
                $staff->availabilityRules()->create([
                    'tenant_id' => $staff->tenant_id,
                    'weekday' => $rule['weekday'],
                    'starts_at' => $rule['starts_at'],
                    'ends_at' => $rule['ends_at'],
                    'effective_from' => $rule['effective_from'] ?? null,
                    'effective_until' => $rule['effective_until'] ?? null,
                ]);
            }
        });

        // Cached availability was computed from the rules that just changed.
        $this->engine->invalidate($staff->tenant_id);

        return AvailabilityRuleResource::collection(
            $staff->availabilityRules()->orderBy('weekday')->orderBy('starts_at')->get()
        );
    }
}
