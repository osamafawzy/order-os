<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Models\Order;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

/**
 * A user only ever sees their own orders, and someone else's order is
 * indistinguishable from one that does not exist.
 */
class OrderOwnershipTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    private Order $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
        $this->foreign = Order::factory()->withItems()->create();
    }

    public function test_show_returns_an_own_order_with_items(): void
    {
        $order = Order::factory()->withItems()->for($this->user)->create();

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', '60.99')
            ->assertJsonCount(2, 'data.items');
    }

    public function test_someone_elses_order_looks_exactly_like_a_missing_one(): void
    {
        $foreign = $this->getJson("/api/v1/orders/{$this->foreign->id}");
        $missing = $this->getJson('/api/v1/orders/999999');

        $foreign->assertNotFound()->assertExactJson([
            'message' => 'The requested resource was not found.',
            'code' => 'resource_not_found',
        ]);

        $this->assertSame($missing->json(), $foreign->json());
        $this->assertStringNotContainsString('Order', (string) $foreign->getContent());
    }

    public function test_someone_elses_order_cannot_be_updated(): void
    {
        $this->patchJson("/api/v1/orders/{$this->foreign->id}", ['customer_name' => 'Mallory', 'status' => 'cancelled'])
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');

        $this->foreign->refresh();
        $this->assertNotSame('Mallory', $this->foreign->customer_name);
        $this->assertSame(OrderStatus::Pending, $this->foreign->status);
    }

    public function test_someone_elses_order_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/v1/orders/{$this->foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');

        $this->assertModelExists($this->foreign);
    }

    public function test_every_order_endpoint_requires_a_token(): void
    {
        $this->withoutToken();

        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson("/api/v1/orders/{$this->foreign->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/orders/{$this->foreign->id}", ['notes' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/v1/orders/{$this->foreign->id}")->assertUnauthorized();
    }
}
