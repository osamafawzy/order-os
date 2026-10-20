<?php

namespace Modules\Payments\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\MakesPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class CardPaymentTest extends TestCase
{
    use InteractsWithJwt;
    use MakesPayments;
    use RefreshDatabase;

    private User $user;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 10, 20, 12));
        $this->user = $this->actingAsJwt();
        $this->order = $this->confirmedOrder($this->user);
    }

    public function test_an_approved_card_payment_is_201_and_charges_the_order_total(): void
    {
        $response = $this->pay($this->order, $this->card());

        $payment = Payment::query()->sole();

        $response->assertCreated()
            ->assertHeader('Location', route('api.v1.payments.show', $payment))
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.order_id', $this->order->id)
            ->assertJsonPath('data.method', 'credit_card')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', '60.99')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.failure', null)
            ->assertJsonPath('data.details', ['card_brand' => 'visa', 'card_last4' => '4242', 'mode' => 'sandbox'])
            ->assertJsonPath('data.processed_at', now()->toIso8601String())
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.idempotency_key');

        $this->assertMatchesRegularExpression('/^ch_[A-Z0-9]{24}$/', (string) $response->json('data.transaction_id'));
        $this->assertSame($this->user->id, $payment->user_id);
        $this->assertSame(PaymentStatus::Successful, $payment->status);
    }

    public function test_neither_the_full_card_number_nor_the_cvc_is_stored_or_returned(): void
    {
        $response = $this->pay($this->order, $this->card('4242 4242 4242 4242', ['cvc' => '9931']));

        $row = json_encode(DB::table('payments')->first());

        foreach ([(string) $response->getContent(), (string) $row] as $haystack) {
            $this->assertStringNotContainsString('4242424242424242', $haystack);
            $this->assertStringNotContainsString('4242 4242 4242 4242', $haystack);
            $this->assertStringNotContainsString('9931', $haystack);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function declinedCards(): array
    {
        return [
            'card declined' => [CreditCardGateway::CARD_DECLINED, 'card_declined', 'Your card was declined.'],
            'insufficient funds' => [CreditCardGateway::CARD_INSUFFICIENT_FUNDS, 'insufficient_funds', 'Your card has insufficient funds.'],
        ];
    }

    #[DataProvider('declinedCards')]
    public function test_a_declined_card_is_402_with_the_payment_in_the_body(string $number, string $code, string $message): void
    {
        $response = $this->pay($this->order, $this->card($number));

        $response->assertStatus(402)
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', ['code' => $code, 'message' => $message])
            ->assertJsonPath('data.amount', '60.99');

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('data.id'),
            'status' => 'failed',
            'failure_code' => $code,
        ]);
    }

    public function test_an_expired_card_is_402_expired_card(): void
    {
        $this->pay($this->order, $this->card(overrides: ['exp_month' => 9, 'exp_year' => 2026]))
            ->assertStatus(402)
            ->assertJsonPath('data.failure.code', 'expired_card')
            ->assertJsonPath('data.transaction_id', null);
    }

    public function test_a_card_expiring_this_month_is_accepted(): void
    {
        $this->pay($this->order, $this->card(overrides: ['exp_month' => 10, 'exp_year' => 2026]))->assertCreated();
    }

    public function test_a_declined_payment_can_be_retried_and_both_attempts_are_kept(): void
    {
        $this->pay($this->order, $this->card(CreditCardGateway::CARD_DECLINED))->assertStatus(402);
        $this->pay($this->order, $this->card())->assertCreated()->assertJsonPath('data.status', 'successful');

        $this->assertSame(
            ['failed', 'successful'],
            Payment::query()->orderBy('id')->pluck('status')->map->value->all(),
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function invalidDetails(): array
    {
        return [
            'number missing' => [['card_number' => null], 'details.card_number', 'The card number field is required.'],
            'number fails luhn' => [['card_number' => '4242424242424241'], 'details.card_number', 'The card number is not valid.'],
            'number has letters' => [['card_number' => '4242-4242-4242-4242'], 'details.card_number', 'The card number field format is invalid.'],
            'month out of range' => [['exp_month' => 13], 'details.exp_month', 'The exp month field must be between 1 and 12.'],
            'year in the past' => [['exp_year' => 2025], 'details.exp_year', 'The exp year field must be at least 2026.'],
            'cvc missing' => [['cvc' => null], 'details.cvc', 'The cvc field is required.'],
            'cvc too short' => [['cvc' => '12'], 'details.cvc', 'The cvc field format is invalid.'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidDetails')]
    public function test_invalid_card_details_are_rejected_before_any_payment_exists(array $overrides, string $field, string $message): void
    {
        $payload = $this->card(overrides: $overrides);
        $payload['details'] = array_filter($payload['details'], static fn ($v): bool => $v !== null);

        $this->pay($this->order, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_card_details_are_required(): void
    {
        $this->pay($this->order, ['method' => 'credit_card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.card_number', 'details.exp_month', 'details.exp_year', 'details.cvc']);
    }

    public function test_the_client_cannot_name_the_amount(): void
    {
        $this->pay($this->order, $this->card() + ['amount' => '0.01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Do not send an amount: the order total is always charged.']);

        $this->assertDatabaseCount('payments', 0);
    }
}
