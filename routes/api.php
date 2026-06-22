<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\StaffController;
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
    });
