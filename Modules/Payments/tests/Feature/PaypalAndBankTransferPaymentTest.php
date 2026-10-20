<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Models\Payment;
use Modules\Payments\Tests\Support\MakesPayments;
use Tests\Concerns\InteractsWithJwt;
use Tests\TestCase;

class PaypalAndBankTransferPaymentTest extends TestCase
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
    }

    public function test_an_approved_paypal_payment_is_201_with_a_masked_payer(): void
    {
        $response = $this->pay($this->order, $this->paypal('jane.buyer@example.com'));

        $response->assertCreated()
            ->assertJsonPath('data.method', 'paypal')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', '60.99')
            ->assertJsonPath('data.details.payer_email', 'j***@example.com');

        $this->assertStringStartsWith('PAYID-', (string) $response->json('data.transaction_id'));
        $this->assertStringNotContainsString('jane.buyer', (string) $response->getContent());
    }

    public function test_a_declined_paypal_payer_is_402(): void
    {
        $this->pay($this->order, $this->paypal('declined@example.com'))
            ->assertStatus(402)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure.code', 'payer_declined');
    }

    public function test_paypal_requires_a_valid_payer_email(): void
    {
        $this->pay($this->order, ['method' => 'paypal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.paypal_email' => 'The paypal email field is required.']);

        $this->pay($this->order, $this->paypal('not-an-email'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.paypal_email' => 'The paypal email field must be a valid email address.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_card_fields_are_not_demanded_for_paypal(): void
    {
        // Gateway rules are per method: PayPal never asks for a card number.
        $this->pay($this->order, $this->paypal())->assertCreated();
    }

    public function test_a_bank_transfer_is_201_pending_with_transfer_instructions(): void
    {
        $response = $this->pay($this->order, $this->bankTransfer());

        $payment = Payment::query()->sole();
        $reference = "ORD-{$this->order->id}-PAY-{$payment->id}";

        $response->assertCreated()
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.processed_at', null)
            ->assertJsonPath('data.transaction_id', $reference)
            ->assertJsonPath('data.details.account_holder', 'Jane Doe')
            ->assertJsonPath('data.details.instructions', [
                'beneficiary' => 'Order OS Ltd.',
                'bank_name' => 'Example Bank',
                'iban' => 'GB82WEST12345698765432',
                'amount' => '60.99',
                'currency' => 'USD',
                'reference' => $reference,
            ]);
    }

    public function test_a_bank_transfer_needs_no_details(): void
    {
        $this->pay($this->order, ['method' => 'bank_transfer'])
            ->assertCreated()
            ->assertJsonMissingPath('data.details.account_holder');
    }

    public function test_a_pending_transfer_blocks_another_payment_by_any_method(): void
    {
        $pendingId = $this->pay($this->order, $this->bankTransfer())->json('data.id');

        $this->pay($this->order, $this->card())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'A payment for this order is already pending. Wait for it to settle before trying again.',
                'code' => 'payment_in_progress',
                'context' => ['payment_id' => $pendingId],
            ]);

        $this->assertDatabaseCount('payments', 1);
    }
}
