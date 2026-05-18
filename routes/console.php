<?php

declare(strict_types=1);

use App\Console\Commands\RescoreBookingsCommand;
use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Scheduled work
|------------------------------------------------------------------------------
|
| Run with `php artisan schedule:work` in development, or a single cron entry
| in production:
|
|     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Risk scores drift as an appointment approaches and as customers build a
// history. Recomputing overnight keeps the morning briefing honest.
Schedule::command(RescoreBookingsCommand::class, ['--days' => 30])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sanctum tokens that nobody has used in three months are just attack surface.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
