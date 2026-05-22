<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Booking rules
    |--------------------------------------------------------------------------
    |
    | These are platform-wide defaults. A tenant can override most of them in
    | its own `settings` JSON column — see App\Models\Tenant::setting().
    |
    */

    'booking' => [
        // How far ahead a customer may book, in days.
        'max_advance_days' => 60,

        // Minimum notice before a slot starts, in minutes. Stops someone
        // booking the 09:00 slot at 08:59.
        'min_notice_minutes' => 120,

        // Granularity of generated slots, in minutes. A 45 minute service on a
        // 15 minute grid yields 09:00, 09:15, 09:30 … not 09:00, 09:45.
        'slot_granularity_minutes' => 15,
    ],
];
