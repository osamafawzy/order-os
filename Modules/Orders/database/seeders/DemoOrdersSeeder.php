<?php

namespace Modules\Orders\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Database\Seeders\DemoUserSeeder;
use Modules\Auth\Models\User;
use Modules\Orders\DTOs\CreateOrderData;
use Modules\Orders\DTOs\UpdateOrderData;
use Modules\Orders\Services\OrderService;

/**
 * One order in each status, built through OrderService so the totals and
 * timestamps are exactly what the API would have produced.
 */
class DemoOrdersSeeder extends Seeder
{
    public function run(OrderService $orders): void
    {
        $this->call(DemoUserSeeder::class);

        $user = User::query()->where('email', DemoUserSeeder::EMAIL)->firstOrFail();

        if ($orders->paginate($user->id)->total() > 0) {
            return;
        }

        $orders->create($user->id, CreateOrderData::fromArray([
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'items' => [
                ['product_name' => 'Mechanical Keyboard', 'quantity' => 1, 'price' => '89.90'],
                ['product_name' => 'Desk Mat', 'quantity' => 2, 'price' => '15.00'],
            ],
            'notes' => 'Still a draft — edit me.',
        ]));

        foreach ([['Laptop Stand', '45.00'], ['27" Monitor', '249.99']] as [$product, $price]) {
            $order = $orders->create($user->id, CreateOrderData::fromArray([
                'customer_name' => 'John Smith',
                'customer_email' => 'john@example.com',
                'customer_phone' => '+1 555 0100',
                'items' => [['product_name' => $product, 'quantity' => 1, 'price' => $price]],
            ]));

            $orders->update($order, UpdateOrderData::fromArray(['status' => 'confirmed']));
        }

        $cancelled = $orders->create($user->id, CreateOrderData::fromArray([
            'customer_name' => 'Ana Lopez',
            'customer_email' => 'ana@example.com',
            'items' => [['product_name' => 'Webcam', 'quantity' => 1, 'price' => '59.00']],
        ]));

        $orders->update($cancelled, UpdateOrderData::fromArray(['status' => 'cancelled']));
    }
}
