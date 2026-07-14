<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PublicController;
use App\Http\Controllers\Web\WebAuthController;
use App\Http\Middleware\ResolveDemoTenant;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Browser routes
|------------------------------------------------------------------------------
|
| Inertia pages. These render the initial state; everything interactive after
| that talks to /api/v1 from the browser.
|
*/

Route::middleware(ResolveDemoTenant::class)->group(function (): void {

    Route::get('/', [PublicController::class, 'home'])->name('home');
    Route::get('/book', [PublicController::class, 'book'])->name('book');
    Route::get('/booking/{reference}', [PublicController::class, 'confirmation'])
        ->where('reference', '[A-Z0-9\-]+')
        ->name('booking.show');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [WebAuthController::class, 'show'])->name('login');
        Route::post('/login', [WebAuthController::class, 'store']);
    });

    Route::post('/logout', [WebAuthController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

});
