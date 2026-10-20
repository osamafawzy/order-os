<?php

return [
    'attributes' => [
        'customer_name' => 'customer name',
        'customer_email' => 'customer email',
        'customer_phone' => 'customer phone',
        'items' => 'items',
        'items.*.product_name' => 'product name of item #:position',
        'items.*.quantity' => 'quantity of item #:position',
        'items.*.price' => 'price of item #:position',
    ],
    'items_required' => 'An order needs at least one item.',
    'price_format' => 'The :attribute must be a positive amount with at most two decimal places.',
    'status_on_create' => 'New orders always start as pending; change the status with an update.',
    'total_too_large' => 'The order total may not exceed :max. Split it into smaller orders.',
    'status_values' => 'The status must be one of: :values.',
    'empty_update' => 'Send at least one field to update: customer_name, customer_email, customer_phone, notes, items or status.',
];
