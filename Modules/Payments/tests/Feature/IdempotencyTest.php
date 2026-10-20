<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Events\PaymentProcessed;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\MakesPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private const KEY = 'checkout-7f3a9c1e';

    private User $user;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsJwt();
        $this->order = $this->confirmedOrder($this->user);
    }

    public function test_replaying_a_key_returns_the_original_payment_without_charging_again(): void
    {
        Event::fake([PaymentProcessed::class]);

        $first = $this->pay($this->order, $this->card(), ['Idempotency-Key' => self::KEY]);
        $first->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $replay = $this->pay($this->order, $this->card(), ['Idempotency-Key' => self::KEY]);

        $replay->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.transaction_id', $first->json('data.transaction_id'))
            ->assertJsonPath('data.status', 'successful');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(self::KEY, Payment::query()->sole()->idempotency_key);
        Event::assertDispatchedTimes(PaymentProcessed::class, 1);
    }

    public function test_a_replay_answers_with_the_original_even_if_the_body_differs(): void
    {
        $firstId = $this->pay($this->order, $this->card(), ['Idempotency-Key' => self::KEY])->json('data.id');

        $this->pay($this->order, $this->paypal(), ['Idempotency-Key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.method', 'credit_card');
    }

    public function test_a_replayed_decline_is_returned_as_is_rather_than_charged_again(): void
    {
        $first = $this->pay($this->order, $this->card(CreditCardGateway::CARD_DECLINED), ['Idempotency-Key' => self::KEY]);
        $first->assertStatus(402);

        $this->pay($this->order, $this->card(CreditCardGateway::CARD_DECLINED), ['Idempotency-Key' => self::KEY])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_new_key_after_a_decline_is_a_new_attempt(): void
    {
        $this->pay($this->order, $this->card(CreditCardGateway::CARD_DECLINED), ['Idempotency-Key' => 'attempt-0001'])->assertStatus(402);
        $this->pay($this->order, $this->card(), ['Idempotency-Key' => 'attempt-0002'])->assertCreated();

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_reusing_a_key_for_a_different_order_is_refused(): void
    {
        $other = $this->confirmedOrder($this->user);

        $this->pay($this->order, $this->card(), ['Idempotency-Key' => self::KEY])->assertCreated();

        $this->pay($other, $this->card(), ['Idempotency-Key' => self::KEY])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'This Idempotency-Key was already used for a different order.',
                'code' => 'idempotency_key_reused',
            ]);

        $this->assertSame(0, Payment::query()->where('order_id', $other->id)->count());
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $this->pay($this->order, $this->card(), ['Idempotency-Key' => self::KEY])->assertCreated();

        $stranger = User::factory()->create();
        $strangersOrder = $this->confirmedOrder($stranger);

        $this->withToken($this->issueJwt($stranger));
        $this->pay($strangersOrder, $this->card(), ['Idempotency-Key' => self::KEY])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('data.order_id', $strangersOrder->id);

        $this->assertDatabaseCount('payments', 2);
    }

    public function test_without_a_key_a_second_request_is_a_conflict_not_a_replay(): void
    {
        $this->pay($this->order, $this->card())->assertCreated();

        $this->pay($this->order, $this->card())
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_already_paid');
    }

    /** @return array<string, array{0: string}> */
    public static function malformedKeys(): array
    {
        return [
            'too short' => ['abc'],
            'contains spaces' => ['has spaces in it'],
            'contains a slash' => ['order/12345678'],
            'too long' => [str_repeat('k', 256)],
        ];
    }

    #[DataProvider('malformedKeys')]
    public function test_a_malformed_key_is_a_validation_error(string $key): void
    {
        $this->pay($this->order, $this->card(), ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'Idempotency-Key' => 'The Idempotency-Key header must be 8 to 255 characters: letters, digits, "-", "_", ":" or ".".',
            ]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_uuid_is_an_acceptable_key(): void
    {
        $this->pay($this->order, $this->card(), ['Idempotency-Key' => '7b1f9c2e-4d3a-4f6b-9a8e-0c5d2e1f3a4b'])->assertCreated();
    }
}
