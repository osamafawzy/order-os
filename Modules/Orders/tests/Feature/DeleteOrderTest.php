<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class DeleteOrderTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    public function test_deleting_an_order_removes_it_and_its_items(): void
    {
        $order = Order::factory()->withItems()->for($this->user)->create();

        $this->deleteJson("/api/v1/orders/{$order->id}")->assertNoContent();

        $this->assertModelMissing($order);
        $this->assertDatabaseCount('order_items', 0);
        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
    }

    public function test_a_confirmed_order_without_payments_can_be_deleted(): void
    {
        $order = Order::factory()->withItems()->confirmed()->for($this->user)->create();

        $this->deleteJson("/api/v1/orders/{$order->id}")->assertNoContent();

        $this->assertModelMissing($order);
    }

    public function test_an_order_with_a_successful_payment_cannot_be_deleted(): void
    {
        $order = Order::factory()->withItems()->confirmed()->for($this->user)->create();
        Payment::factory()->forOrder($order)->create();

        $this->deleteJson("/api/v1/orders/{$order->id}")
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'This order cannot be deleted because it has payments associated with it.',
                'code' => 'order_has_payments',
            ]);

        $this->assertModelExists($order);
    }

    public function test_an_order_with_only_a_failed_payment_cannot_be_deleted_either(): void
    {
        $order = Order::factory()->withItems()->confirmed()->for($this->user)->create();
        Payment::factory()->forOrder($order)->failed()->create();

        $this->deleteJson("/api/v1/orders/{$order->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_has_payments');

        $this->assertModelExists($order);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_deleting_a_missing_order_is_a_404(): void
    {
        $this->deleteJson('/api/v1/orders/424242')
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    }
}
