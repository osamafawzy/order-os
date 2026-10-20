<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class OrderStatusTransitionTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    private function order(OrderStatus $status): Order
    {
        return Order::factory()->withItems()->for($this->user)->create([
            'status' => $status,
            'confirmed_at' => $status === OrderStatus::Confirmed ? now() : null,
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : null,
        ]);
    }

    /** @return array<string, array{0: OrderStatus, 1: OrderStatus, 2: string}> */
    public static function allowedTransitions(): array
    {
        return [
            'pending to confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed, 'confirmed_at'],
            'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, 'cancelled_at'],
            'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, 'cancelled_at'],
        ];
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions_change_status_and_stamp_the_time(OrderStatus $from, OrderStatus $to, string $timestamp): void
    {
        $this->freezeSecond();
        $order = $this->order($from);

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => $to->value])
            ->assertOk()
            ->assertJsonPath('data.status', $to->value)
            ->assertJsonPath("data.{$timestamp}", now()->toIso8601String());

        $this->assertSame($to, $order->refresh()->status);
    }

    /** @return array<string, array{0: OrderStatus, 1: OrderStatus, 2: array<int, string>}> */
    public static function forbiddenTransitions(): array
    {
        return [
            'confirmed back to pending' => [OrderStatus::Confirmed, OrderStatus::Pending, ['cancelled']],
            'cancelled to pending' => [OrderStatus::Cancelled, OrderStatus::Pending, []],
            'cancelled to confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed, []],
        ];
    }

    /** @param array<int, string> $allowed */
    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transitions_are_refused_with_the_allowed_targets(OrderStatus $from, OrderStatus $to, array $allowed): void
    {
        $order = $this->order($from);

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => $to->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_status_transition')
            ->assertJsonPath('message', "An order cannot move from {$from->value} to {$to->value}.")
            ->assertJsonPath('context.from', $from->value)
            ->assertJsonPath('context.to', $to->value)
            ->assertJsonPath('context.allowed', $allowed);

        $this->assertSame($from, $order->refresh()->status);
    }

    public function test_sending_the_current_status_is_a_no_op(): void
    {
        $order = $this->order(OrderStatus::Confirmed);
        $confirmedAt = $order->confirmed_at;

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals($confirmedAt, $order->refresh()->confirmed_at);
    }

    public function test_an_order_with_a_successful_payment_cannot_be_cancelled(): void
    {
        $order = $this->order(OrderStatus::Confirmed);
        Payment::factory()->forOrder($order)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_already_paid')
            ->assertJsonPath('message', 'This order has a successful payment and can no longer be cancelled.');

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_an_order_with_a_pending_payment_cannot_be_cancelled(): void
    {
        $order = $this->order(OrderStatus::Confirmed);
        Payment::factory()->forOrder($order)->pending()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This order has a payment awaiting settlement and cannot be cancelled until it completes or fails.',
                'code' => 'payment_in_progress',
            ]);

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_an_order_with_only_failed_payments_can_still_be_cancelled(): void
    {
        $order = $this->order(OrderStatus::Confirmed);
        Payment::factory()->forOrder($order)->failed()->count(2)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
