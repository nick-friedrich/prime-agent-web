<?php

return [
    'home' => env('PRIME_AGENT_HOME'),

    'discovery_roots' => env('PRIME_AGENT_PROJECT_ROOTS')
        ?: '~/dev'.PATH_SEPARATOR.'~/Developer'.PATH_SEPARATOR.'~/code'.PATH_SEPARATOR.'~/projects',

    'max_depth' => (int) env('PRIME_AGENT_PROJECT_SCAN_DEPTH', 5),
    'max_directories' => (int) env('PRIME_AGENT_PROJECT_SCAN_LIMIT', 5000),
    'cache_seconds' => (int) env('PRIME_AGENT_PROJECT_CACHE_SECONDS', 60),
];
