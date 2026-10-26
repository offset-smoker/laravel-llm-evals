<?php

declare(strict_types=1);

return [

    /*
     * The directory `php artisan evals:run` reads datasets from. Dataset
     * arguments are resolved inside it and may not point outside it.
     */
    'datasets_path' => base_path('evals'),

    /*
     * Where the `recorded` driver reads `<dataset name>.json` recordings,
     * and where `--record` writes them.
     */
    'recordings_path' => base_path('evals/recordings'),

    /*
     * The driver evaluated when `--driver` is not given. `recorded` makes no
     * network calls, which suits CI; use `claude` to evaluate live output.
     */
    'default_driver' => env('LLM_EVALS_DRIVER', 'recorded'),

    'claude' => [
        // Read from the environment only. Never commit a key to this file.
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('LLM_EVALS_CLAUDE_MODEL', 'claude-opus-5-5'),
        'max_tokens' => (int) env('LLM_EVALS_CLAUDE_MAX_TOKENS', 4096),
        // low, medium, high, xhigh or max.
        'effort' => env('LLM_EVALS_CLAUDE_EFFORT', 'medium'),
    ],

];
