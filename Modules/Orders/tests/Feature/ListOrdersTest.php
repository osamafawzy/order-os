<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class ListOrdersTest extends TestCase
{
    use InteractsWithJwt;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    public function test_it_lists_only_the_callers_orders_with_their_items(): void
    {
        $mine = Order::factory()->withItems()->for($this->user)->create();
        Order::factory()->withItems()->count(3)->create();

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.total', '60.99')
            ->assertJsonCount(2, 'data.0.items')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_it_returns_laravel_pagination_with_newest_first(): void
    {
        $orders = collect(range(1, 5))->map(
            fn (int $i) => Order::factory()->for($this->user)->create(['created_at' => now()->subMinutes(10 - $i)]),
        );

        $response = $this->getJson('/api/v1/orders?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status', 'customer', 'items', 'total', 'currency']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total', 'path', 'links'],
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('data.0.id', $orders[4]->id)
            ->assertJsonPath('data.1.id', $orders[3]->id);

        $this->getJson('/api/v1/orders?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orders[0]->id);
    }

    public function test_default_page_size_is_fifteen(): void
    {
        Order::factory()->for($this->user)->count(16)->create();

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_it_filters_by_status_and_keeps_the_filter_in_pagination_links(): void
    {
        Order::factory()->for($this->user)->count(2)->create();
        $confirmed = Order::factory()->for($this->user)->confirmed()->count(2)->create();
        Order::factory()->for($this->user)->cancelled()->create();

        $response = $this->getJson('/api/v1/orders?status=confirmed&per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.status', 'confirmed');

        $this->assertContains($response->json('data.0.id'), $confirmed->pluck('id')->all());
        $this->assertStringContainsString('status=confirmed', (string) $response->json('links.next'));
    }

    public function test_an_empty_list_is_still_a_paginated_response(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidQueries(): array
    {
        return [
            'unknown status' => ['status=shipped', 'status'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'per_page above 100' => ['per_page=101', 'per_page'],
            'per_page not a number' => ['per_page=lots', 'per_page'],
            'page zero' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_list_parameters_are_rejected(string $query, string $field): void
    {
        $this->getJson('/api/v1/orders?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_unknown_status_message_lists_the_valid_values(): void
    {
        $this->getJson('/api/v1/orders?status=shipped')
            ->assertJsonValidationErrors(['status' => 'The status must be one of: pending, confirmed, cancelled.']);
    }

    public function test_per_page_of_one_hundred_is_allowed(): void
    {
        $this->getJson('/api/v1/orders?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
