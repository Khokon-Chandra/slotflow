<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\DayStatistics;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\Booking;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin · metrics
 */
final class MetricsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DayStatistics $statistics,
    ) {}

    /**
     * Headline numbers
     *
     * Bookings, revenue and the no-show rate over a rolling window, plus
     * today's schedule statistics.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = $this->tenants->require();
        $days = min(365, max(1, $request->integer('days', 30)));
        $since = CarbonImmutable::now()->subDays($days);

        // One grouped query rather than five COUNTs. On a table with an index
        // on (tenant_id, status, starts_at) this is a single index scan.
        $counts = Booking::query()
            ->where('starts_at', '>=', $since)
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'), DB::raw('sum(price_cents) as revenue'))
            ->get()
            ->keyBy('status');

        $completed = (int) ($counts->get(BookingStatus::Completed->value)?->getAttribute('total') ?? 0);
        $noShows = (int) ($counts->get(BookingStatus::NoShow->value)?->getAttribute('total') ?? 0);
        $resolved = $completed + $noShows;

        return response()->json([
            'data' => [
                'window_days' => $days,
                'totals' => [
                    'bookings' => (int) $counts->sum('total'),
                    'completed' => $completed,
                    'cancelled' => (int) ($counts->get(BookingStatus::Cancelled->value)?->getAttribute('total') ?? 0),
                    'no_shows' => $noShows,
                    'upcoming' => Booking::query()->blocking()->upcoming()->count(),
                ],
                'revenue' => [
                    'currency' => $tenant->currency,
                    'realised_cents' => (int) ($counts->get(BookingStatus::Completed->value)?->getAttribute('revenue') ?? 0),
                    // What the no-shows cost. This is the number the whole
                    // risk feature exists to move, so it belongs on the
                    // dashboard next to it.
                    'lost_to_no_shows_cents' => (int) ($counts->get(BookingStatus::NoShow->value)?->getAttribute('revenue') ?? 0),
                ],
                'no_show_rate' => $resolved === 0 ? 0.0 : round($noShows / $resolved, 4),
                'today' => $this->statistics->forDay($tenant, CarbonImmutable::now($tenant->timezone)),
            ],
        ]);
    }

    /**
     * AI usage and spend
     *
     * Every AI call this workspace has made, aggregated by task. What it cost,
     * how long it took, how often it fell back.
     */
    public function aiUsage(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $days = min(365, max(1, $request->integer('days', 30)));

        $rows = AiInteraction::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays($days))
            ->groupBy('task', 'driver')
            ->select(
                'task',
                'driver',
                DB::raw('count(*) as calls'),
                DB::raw('sum(cost_micros) as cost_micros'),
                DB::raw('sum(input_tokens) as input_tokens'),
                DB::raw('sum(output_tokens) as output_tokens'),
                DB::raw('avg(latency_ms) as avg_latency_ms'),
                DB::raw('sum(served_from_cache) as cache_hits'),
                DB::raw('sum(case when succeeded = 0 then 1 else 0 end) as failures'),
            )
            ->get();

        return response()->json([
            'data' => [
                'window_days' => $days,
                'total_cost_usd' => round((int) $rows->sum('cost_micros') / 1_000_000, 4),
                'monthly_budget_usd' => (float) config('ai.monthly_budget_usd'),
                'by_task' => $rows->map(fn (AiInteraction $row) => [
                    'task' => $row->task->value,
                    'driver' => $row->driver,
                    'calls' => (int) $row->getAttribute('calls'),
                    'cost_usd' => round((int) $row->getAttribute('cost_micros') / 1_000_000, 6),
                    'input_tokens' => (int) $row->getAttribute('input_tokens'),
                    'output_tokens' => (int) $row->getAttribute('output_tokens'),
                    'avg_latency_ms' => (int) round((float) $row->getAttribute('avg_latency_ms')),
                    'cache_hits' => (int) $row->getAttribute('cache_hits'),
                    'failures' => (int) $row->getAttribute('failures'),
                ])->values(),
            ],
        ]);
    }
}
