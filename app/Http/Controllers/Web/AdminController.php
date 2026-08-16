<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Ai\AiManager;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Tasks\GenerateDailyBriefing;
use App\Domain\Reporting\DayStatistics;
use App\Enums\BookingStatus;
use App\Enums\RiskBand;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiSettingsResource;
use App\Models\AiInteraction;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TenantAiSettings;
use App\Models\TimeOff;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The business-facing panel.
 *
 * Each method assembles the page's initial props server-side. Anything
 * interactive after that — filtering the diary, asking for slots, drafting
 * copy — goes through /api/v1 from the browser, so the API is exercised by
 * the app's own admin panel rather than only by tests.
 */
final class AdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DayStatistics $statistics,
        private readonly GenerateDailyBriefing $briefing,
        private readonly AiCredentials $credentials,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = $this->tenants->require();
        $today = CarbonImmutable::now($tenant->timezone);

        $stats = $this->statistics->forDay($tenant, $today);

        $todaysBookings = Booking::query()
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->whereIn('status', BookingStatus::blocking())
            ->whereBetween('starts_at', [
                $today->startOfDay()->utc(),
                $today->endOfDay()->utc(),
            ])
            ->orderBy('starts_at')
            ->get();

        $atRisk = Booking::query()
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->whereIn('status', BookingStatus::blocking())
            ->upcoming()
            ->whereHas('riskAssessment', fn ($q) => $q->where('band', RiskBand::High->value))
            ->orderBy('starts_at')
            ->limit(6)
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats,
            'metrics' => $this->headlineMetrics(),
            'todaysBookings' => $todaysBookings->map($this->bookingRow(...))->values(),
            'atRisk' => $atRisk->map($this->bookingRow(...))->values(),

            // Deferred: the briefing may call an external API, and the
            // dashboard should paint immediately either way. Inertia fetches
            // it in a second round trip once the page is on screen.
            'briefing' => Inertia::defer(fn () => ($this->briefing)($tenant, $today)),
        ]);
    }

    public function bookings(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'staff_id' => $request->integer('staff_id') ?: null,
            'risk' => $request->string('risk')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        $user = $request->user();

        $bookings = Booking::query()
            ->with(['service', 'staff', 'customer', 'riskAssessment'])
            ->when($user->isStaff(), fn ($q) => $q->where('staff_id', $user->staffProfile?->id))
            ->when($filters['status'], fn ($q, $v) => $q->where('status', $v))
            ->when($filters['staff_id'], fn ($q, $v) => $q->where('staff_id', $v))
            ->when($filters['risk'], fn ($q, $v) => $q->whereHas('riskAssessment', fn ($r) => $r->where('band', $v)))
            ->when($filters['from'], fn ($q, $v) => $q->where('starts_at', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($filters['to'], fn ($q, $v) => $q->where('starts_at', '<=', CarbonImmutable::parse($v)->endOfDay()))
            ->when($filters['search'], function ($q, $v): void {
                $term = '%'.$v.'%';

                $q->where(fn ($inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->orderByDesc('starts_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Bookings', [
            'bookings' => [
                'data' => collect($bookings->items())->map($this->bookingRow(...))->values(),
                'meta' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'total' => $bookings->total(),
                    'per_page' => $bookings->perPage(),
                ],
            ],
            'filters' => $filters,
            'staffOptions' => Staff::query()->ordered()->get(['id', 'name'])
                ->map(fn (Staff $s) => ['value' => $s->id, 'label' => $s->name]),
            'statusOptions' => collect(BookingStatus::cases())
                ->map(fn (BookingStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function services(): Response
    {
        $this->authorize('viewAny', Booking::class);

        return Inertia::render('Admin/Services', [
            'services' => Service::query()->with('staff')->ordered()->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'description' => $service->description,
                    'keywords' => $service->keywords,
                    'duration_minutes' => $service->duration_minutes,
                    'buffer_minutes' => $service->buffer_minutes,
                    'price_cents' => $service->price_cents,
                    'color' => $service->color,
                    'is_active' => $service->is_active,
                    'requires_deposit' => $service->requires_deposit,
                    'deposit_cents' => $service->deposit_cents,
                    'staff_ids' => $service->staff->pluck('id')->values(),
                    'booking_count' => $service->bookings()->count(),
                ]),
            'staffOptions' => Staff::query()->ordered()->get(['id', 'name'])
                ->map(fn (Staff $s) => ['value' => $s->id, 'label' => $s->name]),
        ]);
    }

    public function team(): Response
    {
        $this->authorize('viewAny', Booking::class);

        return Inertia::render('Admin/Team', [
            'team' => Staff::query()->with(['services', 'availabilityRules', 'timeOff'])->ordered()->get()
                ->map(fn (Staff $staff) => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'title' => $staff->title,
                    'bio' => $staff->bio,
                    'timezone' => $staff->timezone,
                    'is_active' => $staff->is_active,
                    'service_ids' => $staff->services->pluck('id')->values(),
                    'weekly_hours' => round($staff->availabilityRules->sum(
                        fn ($rule) => CarbonImmutable::parse($rule->starts_at)->diffInMinutes(
                            CarbonImmutable::parse($rule->ends_at)
                        ) / 60
                    ), 1),
                    'upcoming_time_off' => $staff->timeOff
                        ->filter(fn ($t) => $t->ends_at->isFuture())
                        ->count(),
                ]),
            'serviceOptions' => Service::query()->ordered()->get(['id', 'name'])
                ->map(fn (Service $s) => ['value' => $s->id, 'label' => $s->name]),
        ]);
    }

    public function availability(Request $request, Staff $staff): Response
    {
        $this->authorize('manageAvailability', $staff);

        // One load with both relations ordered, rather than two ad-hoc
        // queries: the relation properties carry their element types, so the
        // mapping below is checkable rather than a bag of mixed.
        $staff->load([
            'availabilityRules' => fn (HasMany $query) => $query->orderBy('weekday')->orderBy('starts_at'),
            'timeOff' => fn (HasMany $query) => $query->orderBy('starts_at'),
        ]);

        return Inertia::render('Admin/Availability', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'title' => $staff->title,
                'timezone' => $staff->timezone,
            ],
            'rules' => $staff->availabilityRules
                ->map(fn (AvailabilityRule $rule): array => [
                    'id' => $rule->id,
                    'weekday' => $rule->weekday,
                    // The column is a TIME, so it arrives as "09:00:00".
                    'starts_at' => substr($rule->starts_at, 0, 5),
                    'ends_at' => substr($rule->ends_at, 0, 5),
                ])->values(),
            'timeOff' => $staff->timeOff
                ->map(fn (TimeOff $entry): array => [
                    'id' => $entry->id,
                    'starts_at' => $entry->starts_at->setTimezone($staff->timezone)->toIso8601String(),
                    'ends_at' => $entry->ends_at->setTimezone($staff->timezone)->toIso8601String(),
                    'reason' => $entry->reason,
                    'is_past' => $entry->ends_at->isPast(),
                ])->values(),
        ]);
    }

    public function aiUsage(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $days = min(90, max(1, $request->integer('days', 30)));
        $since = CarbonImmutable::now()->subDays($days);

        $byTask = AiInteraction::query()
            ->where('created_at', '>=', $since)
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
            ->get()
            // `calls`, `avg_latency_ms` and friends are aggregate columns, not
            // model attributes — getAttribute() is the accurate way to read a
            // value the model never declared.
            ->map(fn (AiInteraction $row) => [
                'task' => $row->task->value,
                'task_label' => $row->task->label(),
                'driver' => $row->driver,
                'calls' => (int) $row->getAttribute('calls'),
                'cost_usd' => round((int) $row->getAttribute('cost_micros') / 1_000_000, 6),
                'input_tokens' => (int) $row->getAttribute('input_tokens'),
                'output_tokens' => (int) $row->getAttribute('output_tokens'),
                'avg_latency_ms' => (int) round((float) $row->getAttribute('avg_latency_ms')),
                'cache_hits' => (int) $row->getAttribute('cache_hits'),
                'failures' => (int) $row->getAttribute('failures'),
            ]);

        $recent = AiInteraction::query()
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (AiInteraction $row) => [
                'id' => $row->id,
                'task' => $row->task->label(),
                'driver' => $row->driver,
                'model' => $row->model,
                'latency_ms' => $row->latency_ms,
                'cost_usd' => round($row->costUsd(), 6),
                'cached' => $row->served_from_cache,
                'succeeded' => $row->succeeded,
                'failure_reason' => $row->failure_reason,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        $isOwner = $request->user()->isOwner();

        return Inertia::render('Admin/AiUsage', [
            'days' => $days,
            'byTask' => $byTask,
            'recent' => $recent,
            'budget' => [
                // The ceiling in force for this workspace, not the platform
                // default — they diverge the moment an owner sets their own.
                'monthly_usd' => $this->credentials->monthlyBudgetUsd(),
                'spent_this_month_usd' => round(
                    (int) AiInteraction::query()->thisMonth()->billable()->sum('cost_micros') / 1_000_000,
                    4,
                ),
            ],
            'config' => [
                'driver' => (string) config('ai.driver'),
                'model' => $this->credentials->model(),
                'effort' => (string) config('ai.claude.effort'),
                'cache_ttl' => (int) config('ai.cache_ttl'),
                'key_source' => $this->credentials->source(),
            ],

            // Credentials are the owner's business. Staff read the usage
            // figures on this page and see nothing about the key that pays
            // for them — the same line a payment method sits on.
            'canManageCredentials' => $isOwner,
            'aiSettings' => $isOwner
                ? new AiSettingsResource($this->aiSettings()->load('setBy'))
                : null,
            'aiEffective' => $isOwner ? [
                'driver' => app(AiManager::class)->isLive() ? 'claude' : 'heuristic',
                'key_source' => $this->credentials->source(),
                'model' => $this->credentials->model(),
                'monthly_budget_usd' => $this->credentials->monthlyBudgetUsd(),
                'configured_driver' => (string) config('ai.driver'),
            ] : null,
            'aiModels' => $isOwner ? $this->credentials->availableModels() : [],
        ]);
    }

    public function settings(): Response
    {
        $tenant = $this->tenants->require();
        $this->authorize('viewAny', Booking::class);

        return Inertia::render('Admin/Settings', [
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'contact_email' => $tenant->contact_email,
                'phone' => $tenant->phone,
                'description' => $tenant->description,
            ],
            'booking' => [
                'min_notice_minutes' => (int) $tenant->setting('booking.min_notice_minutes'),
                'max_advance_days' => (int) $tenant->setting('booking.max_advance_days'),
                'slot_granularity_minutes' => (int) $tenant->setting('booking.slot_granularity_minutes'),
                'cancellation_window_hours' => (int) $tenant->setting('booking.cancellation_window_hours'),
            ],
        ]);
    }

    /**
     * This workspace's credential row, unsaved if it does not exist yet, so
     * the page has the same shape whether or not anything is configured.
     */
    private function aiSettings(): TenantAiSettings
    {
        return TenantAiSettings::query()
            ->withoutTenantScope()
            ->firstOrNew(['tenant_id' => $this->tenants->require()->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function headlineMetrics(): array
    {
        $counts = Booking::query()
            ->where('starts_at', '>=', CarbonImmutable::now()->subDays(30))
            ->groupBy('status')
            ->select('status', DB::raw('count(*) as total'), DB::raw('sum(price_cents) as revenue'))
            ->get()
            ->keyBy('status');

        $completed = (int) ($counts->get(BookingStatus::Completed->value)?->getAttribute('total') ?? 0);
        $noShows = (int) ($counts->get(BookingStatus::NoShow->value)?->getAttribute('total') ?? 0);
        $resolved = $completed + $noShows;

        return [
            'bookings_30d' => (int) $counts->sum('total'),
            'completed_30d' => $completed,
            'no_shows_30d' => $noShows,
            'no_show_rate' => $resolved === 0 ? 0.0 : round($noShows / $resolved * 100, 1),
            'revenue_30d_cents' => (int) ($counts->get(BookingStatus::Completed->value)?->getAttribute('revenue') ?? 0),
            'lost_to_no_shows_cents' => (int) ($counts->get(BookingStatus::NoShow->value)?->getAttribute('revenue') ?? 0),
            'upcoming' => Booking::query()->blocking()->upcoming()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingRow(Booking $booking): array
    {
        $tenant = $this->tenants->require();

        return [
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'source' => $booking->source->value,
            'source_label' => $booking->source->label(),
            'starts_at' => $booking->starts_at->toIso8601String(),
            'local_starts_at' => $booking->starts_at->setTimezone($tenant->timezone)->toIso8601String(),
            'local_ends_at' => $booking->ends_at->setTimezone($tenant->timezone)->toIso8601String(),
            'duration_minutes' => (int) $booking->starts_at->diffInMinutes($booking->ends_at),
            'price_cents' => $booking->price_cents,
            'service' => ['name' => $booking->service->name, 'color' => $booking->service->color],
            'staff' => ['id' => $booking->staff->id, 'name' => $booking->staff->name],
            'customer' => [
                'name' => $booking->customer->name,
                'email' => $booking->customer->email,
                'phone' => $booking->customer->phone,
                'completed_count' => $booking->customer->completed_count,
                'no_show_count' => $booking->customer->no_show_count,
            ],
            'risk' => $booking->riskAssessment === null ? null : [
                'score' => $booking->riskAssessment->score,
                'band' => $booking->riskAssessment->band->value,
                'band_label' => $booking->riskAssessment->band->label(),
                'factors' => $booking->riskAssessment->factors,
                'rationale' => $booking->riskAssessment->rationale,
                'recommended_action' => $booking->riskAssessment->recommended_action,
                'generated_by' => $booking->riskAssessment->generated_by,
                'model' => $booking->riskAssessment->model,
            ],
        ];
    }
}
