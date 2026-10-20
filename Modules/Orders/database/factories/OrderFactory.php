<?php

namespace Modules\Orders\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Auth\Models\User;
use Modules\Core\Support\Money;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => null,
            'status' => OrderStatus::Pending,
            'currency' => (string) config('orders.currency', 'USD'),
            'total' => '0.00',
            'notes' => null,
        ];
    }

    /**
     * Give the order real items and a total that matches them, so a payment
     * test charges the amount a real order would carry.
     *
     * @param  array<int, array{product_name: string, quantity: int, price: string}>|null  $items
     */
    public function withItems(?array $items = null): static
    {
        $items ??= [
            ['product_name' => 'Wireless Mouse', 'quantity' => 2, 'price' => '25.50'],
            ['product_name' => 'USB-C Cable', 'quantity' => 1, 'price' => '9.99'],
        ];

        return $this->afterCreating(function (Order $order) use ($items): void {
            $total = 0;

            foreach ($items as $item) {
                $line = Money::toMinor($item['price']) * $item['quantity'];
                $total += $line;

                $order->items()->create($item + ['line_total' => Money::fromMinor($line)]);
            }

            $order->forceFill(['total' => Money::fromMinor($total)])->save();
        });
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
