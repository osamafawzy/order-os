<?php

namespace Modules\Payments\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Database\Seeders\DemoUserSeeder;
use Modules\Auth\Models\User;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Services\OrderService;
use Modules\Payments\DTOs\PaymentFilters;
use Modules\Payments\DTOs\ProcessPaymentData;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Services\PaymentService;

/**
 * A declined card followed by a successful retry, on the first confirmed
 * order — so the payment history shows both outcomes. The second confirmed
 * order is left unpaid for the reader to pay from Postman.
 */
class DemoPaymentsSeeder extends Seeder
{
    public function run(OrderService $orders, PaymentService $payments): void
    {
        $user = User::query()->where('email', DemoUserSeeder::EMAIL)->firstOrFail();

        $order = $orders->paginate($user->id, OrderStatus::Confirmed, 100)->getCollection()->sortBy('id')->first();

        if ($order === null || $payments->paginate($user->id, new PaymentFilters(order_id: $order->id))->total() > 0) {
            return;
        }

        $card = fn (string $number): ProcessPaymentData => new ProcessPaymentData('credit_card', [
            'card_number' => $number,
            'exp_month' => 12,
            'exp_year' => now()->year + 2,
            'cvc' => '123',
        ]);

        $payments->process($order, $card(CreditCardGateway::CARD_DECLINED));
        $payments->process($order, $card('4242424242424242'));
    }
}
