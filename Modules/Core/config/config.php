<?php

return [
    'name' => 'Core',

    // Requests per minute for every authenticated API route.
    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT', 60),
];
