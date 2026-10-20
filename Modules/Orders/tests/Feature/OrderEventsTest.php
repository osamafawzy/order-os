<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Models\User;
use Modules\Orders\Enums\OrderStatus;
use Modules\Orders\Events\OrderCreated;
use Modules\Orders\Events\OrderDeleted;
use Modules\Orders\Events\OrderStatusChanged;
use Modules\Orders\Events\OrderUpdated;
use Modules\Orders\Models\Order;
use Modules\Orders\Tests\Support\OrderPayloads;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class OrderEventsTest extends TestCase
{
    use InteractsWithJwt;
    use OrderPayloads;
    use RefreshDatabase;

    private const EVENTS = [
        OrderCreated::class,
        OrderUpdated::class,
        OrderStatusChanged::class,
        OrderDeleted::class,
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();

        // Only the domain events: Eloquent's own events keep firing, so
        // factories and casts behave normally.
        Event::fake(self::EVENTS);
    }

    public function test_creating_an_order_dispatches_order_created(): void
    {
        $id = $this->postJson('/api/v1/orders', $this->orderPayload())->assertCreated()->json('data.id');

        Event::assertDispatchedTimes(OrderCreated::class, 1);
        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $e): bool => $e->order->id === $id
            && $e->order->relationLoaded('items')
            && $e->order->total === '60.99');
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_editing_details_dispatches_order_updated_but_not_status_changed(): void
    {
        $order = Order::factory()->withItems()->for($this->user)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['notes' => 'Updated'])->assertOk();

        Event::assertDispatched(OrderUpdated::class, fn (OrderUpdated $e): bool => $e->order->notes === 'Updated');
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_a_status_change_dispatches_both_events_with_from_and_to(): void
    {
        $order = Order::factory()->withItems()->for($this->user)->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'confirmed'])->assertOk();

        Event::assertDispatched(OrderUpdated::class);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e): bool => $e->order->id === $order->id
            && $e->from === OrderStatus::Pending
            && $e->to === OrderStatus::Confirmed);
    }

    public function test_resending_the_current_status_does_not_dispatch_status_changed(): void
    {
        $order = Order::factory()->for($this->user)->confirmed()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'confirmed'])->assertOk();

        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_refused_changes_dispatch_nothing(): void
    {
        $order = Order::factory()->for($this->user)->cancelled()->create();

        $this->patchJson("/api/v1/orders/{$order->id}", ['status' => 'confirmed'])->assertUnprocessable();
        $this->patchJson("/api/v1/orders/{$order->id}", ['notes' => 'x'])->assertStatus(409);

        Event::assertNothingDispatched();
    }

    public function test_deleting_dispatches_order_deleted_with_ids(): void
    {
        $order = Order::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/orders/{$order->id}")->assertNoContent();

        Event::assertDispatched(OrderDeleted::class, fn (OrderDeleted $e): bool => $e->orderId === $order->id
            && $e->userId === $this->user->id);
    }
}
