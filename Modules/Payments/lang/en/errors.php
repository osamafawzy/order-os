<?php

return [
    'order_not_confirmed' => 'Payments can only be processed for confirmed orders. This order is :status.',
    'order_already_paid' => 'This order has already been paid.',
    'payment_in_progress' => 'A payment for this order is already pending. Wait for it to settle before trying again.',
    'unsupported_method' => 'The payment method ":method" is not supported.',
    'method_unavailable' => 'The payment method ":method" is currently unavailable.',
    'idempotency_key_reused' => 'This Idempotency-Key was already used for a different order.',
    'gateway_error' => 'The payment provider did not confirm the outcome. The payment is pending until it is reconciled; do not pay again.',
    'gateway_unreachable' => 'The payment provider could not be reached. No money was taken; please try again.',
];
