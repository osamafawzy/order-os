<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * Editing an order's details. Status changes are covered in
 * OrderStatusTransitionTest.
 */
class UpdateOrderTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    private function pendingOrder(array $attributes = []): Order
    {
        return Order::factory()->withItems()->for($this->user)->create($attributes + [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '+1 555 0100',
            'notes' => 'Original note',
        ]);
    }

    public function test_patch_changes_only_the_fields_sent(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_name' => 'Janet Doe'])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Janet Doe')
            ->assertJsonPath('data.customer.email', 'jane@example.com')
            ->assertJsonPath('data.customer.phone', '+1 555 0100')
            ->assertJsonPath('data.notes', 'Original note')
            ->assertJsonPath('data.total', '60.99')
            ->assertJsonCount(2, 'data.items');
    }

    /**
     * Updates are partial, which is PATCH. PUT would promise a full
     * replacement, so it is not offered rather than offered with the wrong
     * meaning.
     */
    public function test_put_is_not_supported(): void
    {
        $order = $this->pendingOrder();

        $this->putJson("/api/v1/orders/{$order->id}", ['notes' => 'Ring twice'])
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');

        $this->assertNotSame('Ring twice', $order->refresh()->notes);
    }

    public function test_sending_null_clears_an_optional_field(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer_phone' => null, 'notes' => null])
            ->assertOk()
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.notes', null);

        $this->assertNull($order->refresh()->customer_phone);
    }

    public function test_sending_items_replaces_them_and_recomputes_the_total(): void
    {
        $order = $this->pendingOrder();
        $oldItemIds = $order->items()->pluck('id')->all();

        $this->patchJson("/api/v1/orders/{$order->id}", [
            'items' => [['product_name' => 'Keyboard', 'quantity' => 3, 'price' => '0.10']],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Keyboard')
            ->assertJsonPath('data.items.0.line_total', '0.30')
            ->assertJsonPath('data.total', '0.30');

        $this->assertSame('0.30', $order->refresh()->total);
        $this->assertDatabaseCount('order_items', 1);
        foreach ($oldItemIds as $id) {
            $this->assertDatabaseMissing('order_items', ['id' => $id]);
        }
    }

    public function test_total_cannot_be_set_directly(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", ['total' => '1.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total']);

        $this->assertSame('60.99', $order->refresh()->total);
    }

    public function test_an_empty_update_is_refused(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'body' => 'Send at least one field to update: customer_name, customer_email, customer_phone, notes, items or status.',
            ]);
    }

    public function test_an_update_with_only_unknown_fields_is_refused_as_empty(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", ['customer' => 'typo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidUpdates(): array
    {
        return [
            'empty item list' => [['items' => []], 'items', 'An order needs at least one item.'],
            'bad item price' => [['items' => [['product_name' => 'A', 'quantity' => 1, 'price' => '2.999']]], 'items.0.price', 'The price of item #1 must be a positive amount with at most two decimal places.'],
            'bad email' => [['customer_email' => 'nope'], 'customer_email', 'The customer email field must be a valid email address.'],
            'unknown status' => [['status' => 'shipped'], 'status', 'The status must be one of: pending, confirmed, cancelled.'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidUpdates')]
    public function test_invalid_updates_are_rejected(array $payload, string $field, string $message): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertSame('60.99', $order->refresh()->total);
    }

    /** @return array<string, array{0: string}> */
    public static function lockedStatuses(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'cancelled' => ['cancelled'],
        ];
    }

    #[DataProvider('lockedStatuses')]
    public function test_details_of_a_non_pending_order_cannot_be_edited(string $status): void
    {
        $order = $this->pendingOrder(['status' => $status]);

        $this->patchJson("/api/v1/orders/{$order->id}", [
            'items' => [['product_name' => 'Cheaper thing', 'quantity' => 1, 'price' => '0.01']],
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_not_editable')
            ->assertJsonPath('context.status', $status)
            ->assertJsonPath('message', "Only pending orders can be modified. This order is {$status}; it can only change status.");

        $this->assertSame('60.99', $order->refresh()->total);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_a_pending_order_can_be_corrected_and_confirmed_in_one_request(): void
    {
        $order = $this->pendingOrder();

        $this->patchJson("/api/v1/orders/{$order->id}", [
            'customer_email' => 'fixed@example.com',
            'status' => 'confirmed',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer.email', 'fixed@example.com')
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_confirmed_order_cannot_be_edited_by_sending_a_status_alongside(): void
    {
        $order = $this->pendingOrder(['status' => OrderStatus::Confirmed]);

        $this->patchJson("/api/v1/orders/{$order->id}", [
            'customer_name' => 'Sneaky Edit',
            'status' => 'cancelled',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_not_editable');

        $order->refresh();
        $this->assertSame('Jane Doe', $order->customer_name);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
    }
}
