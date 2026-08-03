<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Tasks\GenerateDailyBriefing;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI · daily briefing
 */
final class BriefingController extends Controller
{
    public function __construct(
        private readonly GenerateDailyBriefing $briefing,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Today's briefing
     *
     * The figures in `stats` are computed by the application. The model only
     * decides which of them matter and writes the sentences.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = $this->tenants->require();

        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->toString(), $tenant->timezone)
            : null;

        return response()->json(['data' => ($this->briefing)($tenant, $date)]);
    }
}
