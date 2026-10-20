<?php

namespace Modules\Payments\DTOs;

/**
 * What a gateway is asked to charge. The amount comes from the order, never
 * from the client: a payment request names an order and a method, not a sum.
 */
final readonly class ChargeRequest
{
    /**
     * @param  array<string, mixed>  $details  the validated gateway-specific fields
     */
    public function __construct(
        public int $paymentId,
        public int $orderId,
        public string $amount,
        public string $currency,
        public string $customerName,
        public string $customerEmail,
        public array $details = [],
    ) {}

    public function detail(string $name, mixed $default = null): mixed
    {
        return $this->details[$name] ?? $default;
    }

    /** What the customer sees on their statement. */
    public function description(): string
    {
        return 'Order #'.$this->orderId;
    }
}
