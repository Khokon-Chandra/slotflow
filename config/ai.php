<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | "auto"      Use Claude when ANTHROPIC_API_KEY is present, otherwise fall
    |             back to the heuristic driver. This is what makes the demo
    |             clonable: it runs with no key and no signup.
    | "claude"    Always call the Anthropic Messages API.
    | "heuristic" Never make a network call. Deterministic local logic.
    |
    */

    'driver' => env('AI_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Claude
    |--------------------------------------------------------------------------
    */

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        // Model IDs are complete as written — never append a date suffix.
        'model' => env('AI_MODEL', 'claude-opus-5'),

        // low | medium | high | xhigh | max. Every task here is a short,
        // well-specified extraction, so "low" is the right default: it keeps
        // latency inside a web request and cost near the floor.
        'effort' => env('AI_EFFORT', 'low'),

        'max_tokens' => (int) env('AI_MAX_TOKENS', 2000),

        'timeout' => (int) env('AI_TIMEOUT', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Identical prompts inside this window reuse the stored result. The daily
    | briefing in particular is read many times per day and changes slowly.
    |
    */

    'cache_ttl' => (int) env('AI_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Spend guard
    |--------------------------------------------------------------------------
    |
    | A soft monthly ceiling, in USD. When the recorded spend for the current
    | month exceeds it, the manager stops calling the API and serves heuristic
    | results instead. Nothing breaks; answers just get simpler.
    |
    */

    'monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 25),

    /*
    |--------------------------------------------------------------------------
    | Pricing (USD per million tokens)
    |--------------------------------------------------------------------------
    |
    | Used only to estimate spend for the guard above and the admin AI usage
    | panel. Keep in sync with platform.claude.com/docs pricing.
    |
    */

    'pricing' => [
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-task rate limits (requests per minute, per tenant)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'booking_intent' => 20,
        'service_copy' => 10,
        'daily_briefing' => 5,
        'no_show_rationale' => 30,
    ],
];
