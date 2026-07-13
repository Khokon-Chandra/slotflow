<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer-facing pages: the shop window, the booking flow and the
 * confirmation.
 */
final class PublicController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function home(): Response
    {
        $tenant = $this->tenants->require();

        return Inertia::render('Public/Home', [
            'services' => Service::query()->active()->ordered()->with('staff')->get()
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'description' => $service->description,
                    'duration_minutes' => $service->duration_minutes,
                    'price_cents' => $service->price_cents,
                    'color' => $service->color,
                    'staff' => $service->staff->map(fn (Staff $s): array => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'title' => $s->title,
                    ])->values()->all(),
                ]),
            'team' => Staff::query()->active()->ordered()->get()
                ->map(fn (Staff $s): array => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'title' => $s->title,
                    'bio' => $s->bio,
                    'timezone' => $s->timezone,
                ]),
            'business' => [
                'description' => $tenant->description,
                'phone' => $tenant->phone,
                'email' => $tenant->contact_email,
            ],
        ]);
    }

    public function book(Request $request): Response
    {
        return Inertia::render('Public/Book', [
            'services' => Service::query()->active()->ordered()->with('staff')->get()
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'description' => $service->description,
                    'duration_minutes' => $service->duration_minutes,
                    'buffer_minutes' => $service->buffer_minutes,
                    'price_cents' => $service->price_cents,
                    'color' => $service->color,
                    'staff' => $service->staff->map(fn (Staff $s): array => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'title' => $s->title,
                    ])->values()->all(),
                ]),
            'preselected' => $request->query('service'),
        ]);
    }

    public function confirmation(Request $request, string $reference): Response
    {
        /** @var Booking $booking */
        $booking = Booking::query()
            ->with(['service', 'staff', 'customer'])
            ->where('reference', $reference)
            ->firstOrFail();

        return Inertia::render('Public/Confirmation', [
            'booking' => [
                'reference' => $booking->reference,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'service' => $booking->service->name,
                'staff' => $booking->staff->name,
                'price_cents' => $booking->price_cents,
                'timezone' => $booking->customer_timezone,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'local_starts_at' => $booking->startsAtForCustomer()->toIso8601String(),
                'customer_name' => $booking->customer->name,
                // Masked. The confirmation URL is shareable by design, and a
                // shareable URL should not hand out a customer's address.
                'customer_email' => $this->maskEmail($booking->customer->email),
                'notes' => $booking->notes,
                'can_cancel' => ! $booking->status->isTerminal() && $booking->isWithinCancellationWindow(),
            ],
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($local, 0, 2);

        return $visible.str_repeat('•', max(3, mb_strlen($local) - 2)).'@'.$domain;
    }
}
