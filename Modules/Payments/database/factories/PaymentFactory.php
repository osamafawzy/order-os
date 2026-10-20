<?php

namespace Modules\Payments\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Orders\Models\Order;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->confirmed()->withItems(),
            'user_id' => fn (array $attributes): int => Order::query()->findOrFail($attributes['order_id'])->user_id,
            'method' => 'credit_card',
            'status' => PaymentStatus::Successful,
            'amount' => fn (array $attributes): string => (string) Order::query()->findOrFail($attributes['order_id'])->total,
            'currency' => 'USD',
            'transaction_id' => 'ch_'.Str::upper(Str::random(24)),
            'processed_at' => now(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => (string) $order->total,
            'currency' => $order->currency,
        ]);
    }

    public function failed(string $code = 'card_declined'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'failure_code' => $code,
            'failure_message' => 'Your card was declined.',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Pending,
            'method' => 'bank_transfer',
            'processed_at' => null,
        ]);
    }
}
