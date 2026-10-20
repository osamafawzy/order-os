<?php

namespace Modules\Orders\Tests\Unit;

use Modules\Orders\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    /** @return array<string, array{0: OrderStatus, 1: OrderStatus, 2: bool}> */
    public static function transitions(): array
    {
        return [
            'pending to confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed, true],
            'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, true],
            'pending to pending' => [OrderStatus::Pending, OrderStatus::Pending, false],
            'confirmed to cancelled' => [OrderStatus::Confirmed, OrderStatus::Cancelled, true],
            'confirmed back to pending' => [OrderStatus::Confirmed, OrderStatus::Pending, false],
            'confirmed to confirmed' => [OrderStatus::Confirmed, OrderStatus::Confirmed, false],
            'cancelled to pending' => [OrderStatus::Cancelled, OrderStatus::Pending, false],
            'cancelled to confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed, false],
            'cancelled to cancelled' => [OrderStatus::Cancelled, OrderStatus::Cancelled, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to(OrderStatus $from, OrderStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
        $this->assertSame($allowed, in_array($to, $from->allowedTransitions(), true));
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertSame([], OrderStatus::Cancelled->allowedTransitions());
    }

    public function test_only_pending_orders_are_editable(): void
    {
        $this->assertTrue(OrderStatus::Pending->isEditable());
        $this->assertFalse(OrderStatus::Confirmed->isEditable());
        $this->assertFalse(OrderStatus::Cancelled->isEditable());
    }

    public function test_only_confirmed_orders_accept_payment(): void
    {
        $this->assertFalse(OrderStatus::Pending->acceptsPayment());
        $this->assertTrue(OrderStatus::Confirmed->acceptsPayment());
        $this->assertFalse(OrderStatus::Cancelled->acceptsPayment());
    }

    public function test_values_lists_every_status(): void
    {
        $this->assertSame(['pending', 'confirmed', 'cancelled'], OrderStatus::values());
    }
}
