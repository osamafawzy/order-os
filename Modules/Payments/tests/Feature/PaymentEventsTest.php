<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentFailed;
use Modules\Payments\Events\PaymentProcessed;
use Modules\Payments\Events\PaymentSucceeded;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Tests\Support\MakesPayments;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class PaymentEventsTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
        $this->order = $this->confirmedOrder($this->user);

        // Only the payment events, so Eloquent keeps working normally.
        Event::fake([PaymentProcessed::class, PaymentSucceeded::class, PaymentFailed::class]);
    }

    public function test_a_successful_payment_dispatches_processed_and_succeeded(): void
    {
        $id = $this->pay($this->order, $this->card())->assertCreated()->json('data.id');

        Event::assertDispatchedTimes(PaymentProcessed::class, 1);
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e): bool => $e->payment->id === $id
            && $e->payment->status === PaymentStatus::Successful
            && $e->payment->order_id === $this->order->id);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_a_decline_dispatches_processed_and_failed(): void
    {
        $this->pay($this->order, $this->card(CreditCardGateway::CARD_DECLINED))->assertStatus(402);

        Event::assertDispatched(PaymentProcessed::class);
        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $e): bool => $e->payment->failure_code === 'card_declined');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_pending_transfer_dispatches_only_processed(): void
    {
        $this->pay($this->order, $this->bankTransfer())->assertCreated();

        Event::assertDispatched(PaymentProcessed::class, fn (PaymentProcessed $e): bool => $e->payment->status === PaymentStatus::Pending);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_refused_requests_dispatch_nothing(): void
    {
        $pending = Order::factory()->withItems()->for($this->user)->create();

        $this->pay($pending, $this->card())->assertStatus(409);
        $this->pay($this->order, ['method' => 'bitcoin'])->assertUnprocessable();
        $this->pay($this->order, $this->card('4242424242424241'))->assertUnprocessable();

        Event::assertNothingDispatched();
    }
}
