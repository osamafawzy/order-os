<?php

namespace Modules\Orders\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Orders\Tests\Support\OrderPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use InteractsWithJwt;
    use OrderPayloads;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
    }

    public function test_creating_an_order_stores_it_as_pending_with_a_computed_total(): void
    {
        $response = $this->postJson('/api/v1/orders', $this->orderPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', '60.99')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.customer', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'phone' => '+1 555 0100',
            ])
            ->assertJsonPath('data.notes', 'Leave at the door')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.cancelled_at', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Wireless Mouse')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.price', '25.50')
            ->assertJsonPath('data.items.0.line_total', '51.00')
            ->assertJsonPath('data.items.1.line_total', '9.99');

        $order = Order::query()->sole();
        $response->assertHeader('Location', route('api.v1.orders.show', $order));

        $this->assertSame($this->user->id, $order->user_id);
        $this->assertSame('60.99', $order->total);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_customer_fields_are_normalised(): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload([
            'customer_name' => '  Jane Doe  ',
            'customer_email' => ' Jane@Example.COM ',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.customer.name', 'Jane Doe')
            ->assertJsonPath('data.customer.email', 'jane@example.com');
    }

    public function test_phone_and_notes_are_optional(): void
    {
        $payload = $this->orderPayload();
        unset($payload['customer_phone'], $payload['notes']);

        $this->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.notes', null);
    }

    /** @return array<string, array{0: array<int, array<string, mixed>>, 1: string}> */
    public static function totals(): array
    {
        return [
            '0.1 + 0.2 is exactly 0.30' => [[
                ['product_name' => 'A', 'quantity' => 1, 'price' => 0.1],
                ['product_name' => 'B', 'quantity' => 1, 'price' => 0.2],
            ], '0.30'],
            '19.99 x 3' => [[['product_name' => 'A', 'quantity' => 3, 'price' => '19.99']], '59.97'],
            'whole-number price' => [[['product_name' => 'A', 'quantity' => 4, 'price' => 5]], '20.00'],
            'one-decimal price' => [[['product_name' => 'A', 'quantity' => 1, 'price' => '7.5']], '7.50'],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    #[DataProvider('totals')]
    public function test_total_is_computed_server_side_in_cents(array $items, string $expected): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload(['items' => $items]))
            ->assertCreated()
            ->assertJsonPath('data.total', $expected);
    }

    public function test_status_cannot_be_set_on_create(): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload(['status' => 'confirmed']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status' => 'New orders always start as pending; change the status with an update.',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_total_cannot_be_sent_by_the_client(): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload(['total' => '0.01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total']);

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Error messages name the item by its position, not "items.1.price".
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidPayloads(): array
    {
        $item = ['product_name' => 'Pen', 'quantity' => 1, 'price' => '1.00'];

        return [
            'customer name missing' => [['customer_name' => null], 'customer_name', 'The customer name field is required.'],
            'customer name too short' => [['customer_name' => 'J'], 'customer_name', 'The customer name field must be at least 2 characters.'],
            'customer email invalid' => [['customer_email' => 'nope'], 'customer_email', 'The customer email field must be a valid email address.'],
            'customer phone invalid' => [['customer_phone' => 'call me'], 'customer_phone', 'The customer phone field format is invalid.'],
            'items missing' => [['items' => null], 'items', 'An order needs at least one item.'],
            'items empty' => [['items' => []], 'items', 'An order needs at least one item.'],
            'too many items' => [['items' => array_fill(0, 101, $item)], 'items', 'The items field must not have more than 100 items.'],
            'product name missing' => [['items' => [['quantity' => 1, 'price' => '1.00']]], 'items.0.product_name', 'The product name of item #1 field is required.'],
            'quantity zero' => [['items' => [$item, ['product_name' => 'B', 'quantity' => 0, 'price' => '1.00']]], 'items.1.quantity', 'The quantity of item #2 field must be at least 1.'],
            'quantity fractional' => [['items' => [['product_name' => 'B', 'quantity' => 1.5, 'price' => '1.00']]], 'items.0.quantity', 'The quantity of item #1 field must be an integer.'],
            'quantity too large' => [['items' => [['product_name' => 'B', 'quantity' => 10001, 'price' => '1.00']]], 'items.0.quantity', 'The quantity of item #1 field must not be greater than 10000.'],
            'price missing' => [['items' => [['product_name' => 'B', 'quantity' => 1]]], 'items.0.price', 'The price of item #1 field is required.'],
            'price not numeric' => [['items' => [['product_name' => 'B', 'quantity' => 1, 'price' => 'free']]], 'items.0.price', 'The price of item #1 field must be a number.'],
            'price with three decimals' => [['items' => [$item, ['product_name' => 'B', 'quantity' => 1, 'price' => '1.005']]], 'items.1.price', 'The price of item #2 must be a positive amount with at most two decimal places.'],
            'price zero' => [['items' => [['product_name' => 'B', 'quantity' => 1, 'price' => '0']]], 'items.0.price', 'The price of item #1 field must be at least 0.01.'],
            'price negative' => [['items' => [['product_name' => 'B', 'quantity' => 1, 'price' => '-5.00']]], 'items.0.price', 'The price of item #1 field must be at least 0.01.'],
            'price too large' => [['items' => [['product_name' => 'B', 'quantity' => 1, 'price' => '1000000.00']]], 'items.0.price', 'The price of item #1 field must not be greater than 999999.99.'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_orders_are_rejected_with_readable_messages(array $overrides, string $field, string $message): void
    {
        $payload = array_filter($this->orderPayload($overrides), static fn ($v): bool => $v !== null);

        $this->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_unknown_keys_inside_an_item_are_rejected(): void
    {
        $this->postJson('/api/v1/orders', $this->orderPayload([
            'items' => [['product_name' => 'Pen', 'quantity' => 1, 'price' => '1.00', 'line_total' => '0.01']],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0']);
    }

    public function test_creating_an_order_requires_authentication(): void
    {
        $this->withoutToken()
            ->postJson('/api/v1/orders', $this->orderPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * orders.total is DECIMAL(12,2): at most 9,999,999,999.99. Each line is
     * capped by validation (999,999.99 x 10,000 fits), but the sum of lines
     * is not, so two maximal lines already overflow the column. On MySQL in
     * strict mode that is an "out of range" error — a 500 — instead of a 422
     * telling the client the order is too large.
     */
    public function test_an_order_whose_total_overflows_the_total_column_is_rejected_as_invalid(): void
    {
        $line = ['product_name' => 'Gold bar', 'quantity' => 10000, 'price' => '999999.99'];

        $this->postJson('/api/v1/orders', $this->orderPayload(['items' => [$line, $line]]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }
}
