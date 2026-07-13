<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PublicController;
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
});
