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
    | Providers
    |--------------------------------------------------------------------------
    |
    | The catalogue of model providers a workspace may connect. Adding one is
    | an entry here — no new class — as long as it speaks a shape a driver
    | already understands.
    |
    | Two drivers cover the field:
    |
    |   anthropic          the official Anthropic SDK
    |   openai_compatible  the OpenAI Chat Completions shape over HTTP, which
    |                      OpenAI, DeepSeek, Groq, Together, Mistral, xAI,
    |                      OpenRouter, Ollama and LM Studio all speak
    |
    | ── About the rates ────────────────────────────────────────────────────
    |
    | `input` and `output` are USD per million tokens, and they exist so this
    | application can estimate spend and enforce a ceiling. Where they are
    | `null`, the rate is unknown and a workspace must supply it before spend
    | tracking means anything.
    |
    | That is deliberate. Shipping a number that was right when this file was
    | written and wrong by the time you read it is worse than shipping none:
    | a wrong rate produces a confident, incorrect bill estimate, and nobody
    | checks a number that looks plausible. Unknown rates are shown as
    | "not tracked" and the UI asks for them.
    |
    */

    'providers' => [

        'anthropic' => [
            'label' => 'Anthropic',
            'driver' => 'anthropic',
            'base_url' => null,
            'key_hint' => 'sk-ant-…',
            'console_url' => 'https://console.anthropic.com/settings/keys',
            'pricing_url' => 'https://platform.claude.com/docs/en/about-claude/pricing',

            // Constrains output at generation time. Where false, the driver
            // asks for JSON in the prompt and validates what comes back.
            'supports_json_schema' => true,

            'models' => [
                'claude-opus-5' => ['label' => 'Claude Opus 5', 'input' => 5.00, 'output' => 25.00],
                'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'input' => 3.00, 'output' => 15.00],
                'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00],
            ],
        ],

        'openai' => [
            'label' => 'OpenAI',
            'driver' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'key_hint' => 'sk-…',
            'console_url' => 'https://platform.openai.com/api-keys',
            'pricing_url' => 'https://openai.com/api/pricing/',
            'supports_json_schema' => true,

            // Rates left unset on purpose — see the note above. Enter yours in
            // the admin panel and spend tracking starts working.
            'models' => [
                'gpt-5' => ['label' => 'GPT-5', 'input' => null, 'output' => null],
                'gpt-5-mini' => ['label' => 'GPT-5 mini', 'input' => null, 'output' => null],
                'gpt-4.1' => ['label' => 'GPT-4.1', 'input' => null, 'output' => null],
                'gpt-4.1-mini' => ['label' => 'GPT-4.1 mini', 'input' => null, 'output' => null],
                'o4-mini' => ['label' => 'o4-mini', 'input' => null, 'output' => null],
            ],
        ],

        'deepseek' => [
            'label' => 'DeepSeek',
            'driver' => 'openai_compatible',
            'base_url' => 'https://api.deepseek.com/v1',
            'key_hint' => 'sk-…',
            'console_url' => 'https://platform.deepseek.com/api_keys',
            'pricing_url' => 'https://api-docs.deepseek.com/quick_start/pricing',

            // DeepSeek has a JSON mode but does not enforce a supplied schema,
            // so the driver falls back to asking in the prompt and validating.
            'supports_json_schema' => false,

            'models' => [
                'deepseek-chat' => ['label' => 'DeepSeek Chat', 'input' => null, 'output' => null],
                'deepseek-reasoner' => ['label' => 'DeepSeek Reasoner', 'input' => null, 'output' => null],
            ],
        ],

        /*
        | Anything else that speaks the OpenAI Chat Completions shape. The
        | workspace supplies the name, the base URL and the model id, so a
        | self-hosted Ollama or a gateway nobody here has heard of works
        | without touching this file.
        */
        'custom' => [
            'label' => 'Custom (OpenAI-compatible)',
            'driver' => 'openai_compatible',
            'base_url' => null,
            'key_hint' => '',
            'console_url' => null,
            'pricing_url' => null,
            'supports_json_schema' => false,
            'requires_base_url' => true,
            'models' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform credentials
    |--------------------------------------------------------------------------
    |
    | Used by any workspace that has not connected a provider of its own. A
    | single-tenant deployment configures this and never opens the settings
    | page.
    |
    */

    'platform' => [
        'provider' => env('AI_PLATFORM_PROVIDER', 'anthropic'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-5'),
        'base_url' => env('AI_PLATFORM_BASE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request shape
    |--------------------------------------------------------------------------
    */

    'request' => [
        // low | medium | high | xhigh | max. Every task here is a short,
        // well-specified extraction, so "low" is the right default: it keeps
        // latency inside a web request and cost near the floor. Providers that
        // have no equivalent ignore it.
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
