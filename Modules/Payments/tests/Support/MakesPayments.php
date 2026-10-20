<?php

namespace Modules\Payments\Tests\Support;

use Illuminate\Testing\TestResponse;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;

/**
 * Shared fixtures for payment feature tests. Expects InteractsWithJwt.
 */
trait MakesPayments
{
    public const GOOD_CARD = '4242424242424242';

    /** A confirmed order with two items, totalling 60.99. */
    protected function confirmedOrder(?User $owner = null): Order
    {
        $factory = Order::factory()->withItems()->confirmed();

        return ($owner ? $factory->for($owner) : $factory)->create()->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function card(string $number = self::GOOD_CARD, array $overrides = []): array
    {
        return [
            'method' => 'credit_card',
            'details' => array_replace([
                'card_number' => $number,
                'exp_month' => 12,
                'exp_year' => now()->year + 2,
                'cvc' => '737',
                'holder_name' => 'Jane Doe',
            ], $overrides),
        ];
    }

    /** @return array<string, mixed> */
    protected function paypal(string $email = 'buyer@example.com'): array
    {
        return ['method' => 'paypal', 'details' => ['paypal_email' => $email]];
    }

    /** @return array<string, mixed> */
    protected function bankTransfer(): array
    {
        return ['method' => 'bank_transfer', 'details' => ['account_holder' => 'Jane Doe']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function pay(Order $order, array $payload, array $headers = []): TestResponse
    {
        return $this->postJson("/api/v1/orders/{$order->id}/payments", $payload, $headers);
    }
}
