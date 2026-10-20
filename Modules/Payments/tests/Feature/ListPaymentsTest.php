<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\MakesPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class ListPaymentsTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    private Order $paidOrder;

    private Order $retriedOrder;

    private Payment $successful;

    private Payment $failed;

    private Payment $retry;

    private Payment $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();

        $this->paidOrder = $this->confirmedOrder($this->user);
        $this->retriedOrder = $this->confirmedOrder($this->user);

        $this->successful = Payment::factory()->forOrder($this->paidOrder)->create(['created_at' => now()->subMinutes(3)]);
        $this->failed = Payment::factory()->forOrder($this->retriedOrder)->failed()->create(['method' => 'paypal', 'created_at' => now()->subMinutes(2)]);
        $this->retry = Payment::factory()->forOrder($this->retriedOrder)->pending()->create(['created_at' => now()->subMinute()]);

        $this->foreign = Payment::factory()->create();
    }

    public function test_it_lists_only_the_callers_payments_newest_first(): void
    {
        $this->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.*.id', [$this->retry->id, $this->failed->id, $this->successful->id])
            ->assertJsonStructure([
                'data' => [['id', 'order_id', 'method', 'status', 'amount', 'currency', 'transaction_id', 'failure', 'details', 'processed_at', 'created_at', 'updated_at']],
                'links',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_it_paginates(): void
    {
        $this->getJson('/api/v1/payments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.*.id', [$this->successful->id]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function filters(): array
    {
        return [
            'status successful' => ['status=successful', 'successful'],
            'status failed' => ['status=failed', 'failed'],
            'status pending' => ['status=pending', 'retry'],
            'method paypal' => ['method=paypal', 'failed'],
            'method bank transfer' => ['method=bank_transfer', 'retry'],
            'order and status combined' => ['status=failed&method=paypal', 'failed'],
        ];
    }

    #[DataProvider('filters')]
    public function test_it_filters(string $query, string $expected): void
    {
        $this->getJson('/api/v1/payments?'.$query)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->{$expected}->id]);
    }

    public function test_it_filters_by_order(): void
    {
        $this->getJson('/api/v1/payments?order_id='.$this->retriedOrder->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->retry->id, $this->failed->id]);
    }

    public function test_filtering_by_someone_elses_order_returns_nothing(): void
    {
        $this->getJson('/api/v1/payments?order_id='.$this->foreign->order_id)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_method_that_is_switched_off_can_still_be_filtered_on(): void
    {
        config(['payments.gateways.paypal.enabled' => false]);

        $this->getJson('/api/v1/payments?method=paypal')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->failed->id]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidFilters(): array
    {
        return [
            'unknown status' => ['status=refunded', 'status'],
            'unknown method' => ['method=bitcoin', 'method'],
            'order id not a number' => ['order_id=abc', 'order_id'],
            'order id zero' => ['order_id=0', 'order_id'],
            'per_page too large' => ['per_page=101', 'per_page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query, string $field): void
    {
        $this->getJson('/api/v1/payments?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_unknown_status_message_lists_the_valid_values(): void
    {
        $this->getJson('/api/v1/payments?status=refunded')
            ->assertJsonValidationErrors(['status' => 'The status must be one of: pending, successful, failed.']);
    }

    public function test_show_returns_an_own_payment(): void
    {
        $this->getJson("/api/v1/payments/{$this->failed->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->failed->id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure.code', 'card_declined')
            ->assertJsonPath('data.amount', '60.99');
    }

    public function test_show_hides_someone_elses_payment(): void
    {
        $this->getJson("/api/v1/payments/{$this->foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    }

    public function test_an_orders_payments_are_listed_under_the_order(): void
    {
        $this->getJson("/api/v1/orders/{$this->retriedOrder->id}/payments")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->retry->id, $this->failed->id]);

        $this->getJson("/api/v1/orders/{$this->retriedOrder->id}/payments?status=failed")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->failed->id]);
    }

    public function test_the_order_in_the_path_wins_over_an_order_id_filter(): void
    {
        $this->getJson("/api/v1/orders/{$this->retriedOrder->id}/payments?order_id={$this->paidOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->retry->id, $this->failed->id]);
    }

    public function test_someone_elses_order_payments_are_hidden(): void
    {
        $this->getJson("/api/v1/orders/{$this->foreign->order_id}/payments")
            ->assertNotFound()
            ->assertJsonPath('code', 'resource_not_found');
    }

    public function test_an_order_without_payments_lists_none(): void
    {
        $order = $this->confirmedOrder($this->user);

        $this->getJson("/api/v1/orders/{$order->id}/payments")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }
}
