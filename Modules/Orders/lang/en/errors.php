<?php

return [
    'has_payments' => 'This order cannot be deleted because it has payments associated with it.',
    'not_editable' => 'Only pending orders can be modified. This order is :status; it can only change status.',
    'invalid_transition' => 'An order cannot move from :from to :to.',
    'payment_pending' => 'This order has a payment awaiting settlement and cannot be cancelled until it completes or fails.',
    'paid_cannot_be_cancelled' => 'This order has a successful payment and can no longer be cancelled.',
];
