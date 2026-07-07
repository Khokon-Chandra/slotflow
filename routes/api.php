<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminBookingController;
use App\Http\Controllers\Api\V1\Admin\MetricsController;
use App\Http\Controllers\Api\V1\Ai\RiskController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\AvailabilityRuleController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\TimeOffController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| SlotFlow API — v1
|------------------------------------------------------------------------------
|
| Versioned from the first commit. `/api/v1` costs nothing today and is the
| only thing that lets you change a response shape in eighteen months without
| breaking every client at once.
|
| Every route runs behind `tenant`, which resolves the workspace from the
| authenticated user or an `X-Tenant` header before any query touches a
| tenant-owned table.
|
| Full reference: docs/API.md · interactive: /docs/api · Postman collection in
| postman/.
|
*/

Route::prefix('v1')
    ->middleware('tenant')
    ->group(function (): void {

        /*
        | Public — no token required.
        |
        | Booking is public on purpose: making a customer create an account
        | before they can make an appointment is the single easiest way to
        | lose the booking.
        */
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);

        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}', [ServiceController::class, 'show']);
        Route::get('staff', [StaffController::class, 'index']);
        Route::get('staff/{staff}', [StaffController::class, 'show']);

        Route::get('availability', [AvailabilityController::class, 'index']);

        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('bookings/{reference}', [BookingController::class, 'show'])
            ->where('reference', '[A-Z0-9\-]+');

        /*
        | Authenticated — any signed-in user.
        */
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::post('auth/logout', [AuthController::class, 'logout']);

            Route::get('bookings', [BookingController::class, 'index']);
            Route::patch('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        });

        /*
        | Admin — owners and staff.
        */
        Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {
            Route::post('services', [ServiceController::class, 'store']);
            Route::put('services/{service}', [ServiceController::class, 'update']);
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);

            Route::post('staff', [StaffController::class, 'store']);
            Route::put('staff/{staff}', [StaffController::class, 'update']);
            Route::delete('staff/{staff}', [StaffController::class, 'destroy']);

            Route::get('staff/{staff}/availability-rules', [AvailabilityRuleController::class, 'index']);
            Route::put('staff/{staff}/availability-rules', [AvailabilityRuleController::class, 'sync']);

            Route::get('staff/{staff}/time-off', [TimeOffController::class, 'index']);
            Route::post('staff/{staff}/time-off', [TimeOffController::class, 'store']);
            Route::delete('staff/{staff}/time-off/{timeOff}', [TimeOffController::class, 'destroy']);

            Route::get('admin/bookings', [AdminBookingController::class, 'index']);
            Route::patch('admin/bookings/{booking}/status', [AdminBookingController::class, 'updateStatus']);

            Route::get('admin/metrics', [MetricsController::class, 'index']);
            Route::get('admin/ai-usage', [MetricsController::class, 'aiUsage']);


            Route::get('bookings/{booking}/risk', RiskController::class);
        });
    });
