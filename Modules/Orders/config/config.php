<?php

return [
    'name' => 'Orders',

    // ISO 4217 code every new order is priced in.
    'currency' => strtoupper((string) env('ORDER_CURRENCY', 'USD')),
];
