# Adding a payment gateway

After this guide you'll have a working **Stripe** gateway: a client can pay an
order with `"method": "stripe"`, it appears in `GET /api/v1/payment-gateways`,
its fields are validated, and it has unit and feature tests. You'll touch
three places, and nothing in the service, controllers, requests, routes or
migrations:

| You touch | What |
|---|---|
| `Modules/Payments/app/Gateways/StripeGateway.php` | New: the gateway class |
| `Modules/Payments/config/config.php` | One block under `gateways` |
| `.env`, `.env.example`, `phpunit.xml` | Its keys |

Allow about 20 minutes. The code below was run against this codebase: the
class through the real registry and `PaymentService`, and the tests under the
project's `phpunit.xml`.

**Before you start**, read *Payment gateways: the extensibility story* in the
[README](../README.md). It explains the registry, the config overlay and
availability. This guide assumes them.

---

## The contract in one screen

```php
interface PaymentGateway
{
    public function key(): string;          // the `method` clients send; stored on every payment
    public function name(): string;         // shown by GET /payment-gateways
    public function isAvailable(): bool;    // enabled AND configured
    public function rules(): array;         // validation for `details.*`, without the prefix
    public function charge(ChargeRequest $request): ChargeResult;
}
```

`AbstractGateway` implements the first three from the gateway's
`GatewayConfig` (key, name, enabled plus required credentials and settings),
and defaults `rules()` to `[]`. That is the template method: the base class
fixes the availability algorithm, and you fill in the steps it calls
(`requiredCredentials()`, `requiredSettings()`). Most gateways extend it and
write two or three methods.

What `charge()` receives (`Modules\Payments\DTOs\ChargeRequest`):

| Property | Meaning |
|---|---|
| `paymentId` | The pending payment row already written for this attempt. Unique, so it makes a good vendor idempotency key |
| `orderId` | The order being paid |
| `amount` | The order total as a two-decimal string, e.g. `"60.99"`. Never the client's figure |
| `currency` | ISO 4217, uppercase, e.g. `"USD"` |
| `customerName`, `customerEmail` | From the order |
| `details` | The validated `details` object from the request. `detail('name')` reads one field |
| `description()` | `"Order #42"`, for statements |

What `charge()` returns (`Modules\Payments\DTOs\ChargeResult`). Use one of the
three named constructors:

```php
ChargeResult::successful(string $transactionId, array $meta = []);
ChargeResult::pending(?string $transactionId = null, array $meta = []);   // accepted, settles later
ChargeResult::failed(string $code, string $message, ?string $transactionId = null, array $meta = []);
```

`meta` is stored on the payment and returned to the client as `details`.

There is a fourth constructor, `ChargeResult::unknown()`, but it's not for
gateways. `PaymentService` uses it when `charge()` throws something other
than `GatewayUnreachable`: the payment stays `pending` with a failure code,
because nobody knows whether money moved.

What `charge()` may throw:

| Throw | Meaning | Result |
|---|---|---|
| `Modules\Payments\Exceptions\GatewayUnreachable` | The request **provably never left**: DNS, connection refused, TLS handshake, an open circuit breaker | Payment `failed` (`gateway_unreachable`), Idempotency-Key released, `502`, retry safe |
| Anything else | No answer, and the vendor may have charged: a timeout above all, or a 5xx | Payment stays `pending` (`gateway_error`), key kept, `502`, blocks new payments until reconciled |

---

## Step 1: Write the gateway class

Create `Modules/Payments/app/Gateways/StripeGateway.php`. It is the whole
integration.

It confirms a Stripe PaymentIntent with a **payment method token** (`pm_…`)
that the client got from Stripe.js or the mobile SDK. The card number never
reaches this API at all, which is how a real card integration should work.

```php
<?php

namespace Modules\Payments\Gateways;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Modules\Core\Support\Money;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Support\GatewayConfig;

/**
 * Stripe PaymentIntents, confirmed server-side with a payment method token.
 *
 * The client tokenises the card with Stripe.js and sends only `pm_…`, so no
 * card number ever touches this API.
 */
class StripeGateway extends AbstractGateway
{
    public function __construct(
        GatewayConfig $config,          // passed by name from GatewayRegistry
        private readonly Http $http,    // resolved by the container
    ) {
        parent::__construct($config);
    }

    /**
     * Fields under `details`, without the prefix. These drive validation of
     * POST /orders/{id}/payments and the `fields` list in GET /payment-gateways.
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', 'starts_with:pm_', 'max:255'],
        ];
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        try {
            $response = $this->send($request);
        } catch (ConnectionException $e) {
            // Only a failure that provably happened before the request left
            // may say "unreachable": the payment is then failed and a retry
            // is safe. Anything else (a read timeout above all) is rethrown
            // as-is, an unknown outcome the service keeps pending.
            if ($this->neverSent($e)) {
                throw new GatewayUnreachable('Stripe could not be reached: '.$e->getMessage(), previous: $e);
            }

            throw $e;
        }

        // 402 is Stripe's "card error": a decline. A decline is an answer,
        // not an exception, so it comes back as a failed result.
        if ($response->status() === 402) {
            $error = (array) $response->json('error', []);

            return ChargeResult::failed(
                (string) ($error['decline_code'] ?? $error['code'] ?? 'card_declined'),
                (string) ($error['message'] ?? __('payments::gateways.card_declined')),
                $error['payment_intent']['id'] ?? null,
                $this->cardMeta((array) ($error['payment_method'] ?? [])),
            );
        }

        // Any other non-2xx (Stripe 500, a bad key) throws a RequestException:
        // we got no usable answer, so PaymentService records an unknown
        // outcome (pending, gateway_error) and answers 502.
        $response->throw();

        $intent = (array) $response->json();
        $meta = [
            'stripe_status' => $intent['status'] ?? null,
            'mode' => $this->config->setting('mode', 'sandbox'),
        ];

        return match ($intent['status'] ?? null) {
            'succeeded' => ChargeResult::successful((string) $intent['id'], $meta),
            // 3-D Secure or an async method: settled later by a webhook.
            'processing', 'requires_action' => ChargeResult::pending((string) $intent['id'], $meta),
            default => ChargeResult::failed(
                'stripe_'.($intent['status'] ?? 'unknown'),
                'The payment was not completed.',
                $intent['id'] ?? null,
                $meta,
            ),
        };
    }

    private function send(ChargeRequest $request): Response
    {
        return $this->http
            ->baseUrl((string) $this->config->setting('base_url', 'https://api.stripe.com'))
            ->withToken((string) $this->config->credential('secret_key'))
            // Our payment id is unique per attempt: if this call is retried
            // after a timeout, Stripe will not charge twice.
            ->withHeaders(['Idempotency-Key' => 'order-os-payment-'.$request->paymentId])
            ->asForm()
            ->timeout(15)
            ->post('/v1/payment_intents', [
                'amount' => Money::toMinor($request->amount),   // Stripe wants cents
                'currency' => strtolower($request->currency),
                'payment_method' => $request->detail('payment_method'),
                'confirm' => 'true',
                'description' => $request->description(),
                'receipt_email' => $request->customerEmail,
                'metadata' => ['order_id' => $request->orderId, 'payment_id' => $request->paymentId],
                'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            ]);
    }

    /**
     * True only for failures before the request reached Stripe: DNS, refused
     * connection, TLS handshake. Laravel wraps every transport failure in a
     * ConnectionException, read timeouts included, so the Guzzle cause is
     * what tells them apart. A connect *timeout* is excluded on purpose:
     * Guzzle classifies it from curl's error text, and when in doubt an
     * unknown outcome is the safe answer.
     */
    private function neverSent(ConnectionException $e): bool
    {
        $cause = $e->getPrevious();

        return $cause instanceof ConnectException
            && ! $cause instanceof ConnectTimeoutException;
    }

    /** Without these, isAvailable() is false and the method is refused. */
    protected function requiredCredentials(): array
    {
        return ['secret_key'];
    }

    /**
     * Brand and last four only — enough for a customer to recognise the card.
     *
     * @param  array<string, mixed>  $paymentMethod
     * @return array<string, string>
     */
    private function cardMeta(array $paymentMethod): array
    {
        return array_filter([
            'card_brand' => $paymentMethod['card']['brand'] ?? null,
            'card_last4' => $paymentMethod['card']['last4'] ?? null,
        ]);
    }
}
```

### Three kinds of failure

`charge()` sorts every way a call can go wrong into one of three, and the
difference matters to the customer's money:

| What happened | What the gateway does | What `PaymentService` does |
|---|---|---|
| Stripe answered "no" (a 402 card error) | Returns `ChargeResult::failed()` | Payment `failed`, `402`, retry allowed |
| The request **provably never left** (DNS failure, connection refused, TLS handshake failed) | Throws `GatewayUnreachable` | Payment `failed` with `gateway_unreachable`, Idempotency-Key released, `502 gateway_unreachable`, retry safe |
| Anything else: a timeout, a Stripe 5xx, a bad key | Lets the exception through | Payment **stays `pending`** with `gateway_error`, key kept, `502 gateway_error`, no new payment until reconciled |

The middle row is the one that needs care. Laravel's HTTP client wraps
*every* transport failure in `Illuminate\Http\Client\ConnectionException`,
including a **read timeout**, where Stripe may well have received the request
and charged the card. So catching `ConnectionException` and calling it
"unreachable" would turn timeouts into failed payments that the client then
retries: a double charge. Look at the cause instead. This project runs Guzzle
8, which types connect-phase failures as `GuzzleHttp\Exception\ConnectException`
(cURL errors 5, 6, 7, 35, 60 and the other handshake codes), and timeouts as
`ConnectTimeoutException`, `NetworkTimeoutException` or
`ResponseTimeoutException`. `neverSent()` accepts the first and rejects every
timeout, a connect timeout included. On Guzzle 7, check
`$cause->getHandlerContext()['errno']` against 6, 7 and 35 instead, and
**never 28** (`CURLE_OPERATION_TIMEDOUT`), which covers read timeouts too.

When you're not sure, let it throw. An unknown outcome costs a reconciliation.
A wrong "unreachable" can cost the customer twice.

### How the constructor gets its arguments

`GatewayRegistry::get()` builds every gateway like this:

```php
$this->container->make($config->driver, ['config' => $config]);
```

So:

- **The parameter must be named `$config`.** It is matched by name, not by
  type. Call `parent::__construct($config)` so that `AbstractGateway` has it.
- **Every other parameter is resolved by the container**: `Http` here, but
  equally `Psr\Log\LoggerInterface`, `Illuminate\Contracts\Cache\Repository`,
  or a vendor SDK client you bind in a provider. No factory, no registration
  call, no change to the registry.
- Because `Illuminate\Http\Client\Factory` comes from the container,
  `Http::fake()` in a test fakes the very instance the gateway holds (step 5).

The class sits under the existing `Modules\Payments\` PSR-4 root, so it
autoloads without a `composer dump-autoload`.

---

## Step 2: Add the config block

In `Modules/Payments/config/config.php`, add an entry under `gateways`. **The
array key is the `method` clients will send**, and it is stored on every
payment. Choose it once.

```php
use Modules\Payments\Gateways\StripeGateway;

// ...

'gateways' => [
    // credit_card, paypal, bank_transfer ...

    'stripe' => [
        'driver' => StripeGateway::class,
        'name' => 'Stripe',
        'enabled' => $bool('PAYMENT_STRIPE_ENABLED', false),
        'credentials' => [
            'secret_key' => env('PAYMENT_STRIPE_SECRET_KEY'),
            'publishable_key' => env('PAYMENT_STRIPE_PUBLISHABLE_KEY'),
        ],
        'settings' => [
            'mode' => env('PAYMENT_STRIPE_MODE', 'sandbox'),
            'base_url' => env('PAYMENT_STRIPE_BASE_URL', 'https://api.stripe.com'),
        ],
    ],
],
```

- `$bool` is the helper already defined at the top of the file
  (`filter_var(..., FILTER_VALIDATE_BOOL)`), so `"false"` in `.env` really is
  false.
- **Secrets go in `credentials`, and every value comes from `env()`**, never a
  literal. Non-secret options go in `settings`.
- Defaulting `enabled` to `false` means a deploy that ships the class before
  the keys can't expose a half-configured gateway.

---

## Step 3: Add the environment keys

`.env.example` (and your `.env`):

```dotenv
PAYMENT_STRIPE_ENABLED=true
PAYMENT_STRIPE_MODE=sandbox
PAYMENT_STRIPE_SECRET_KEY=sk_test_...
PAYMENT_STRIPE_PUBLISHABLE_KEY=pk_test_...
```

`phpunit.xml`, next to the other gateways' sandbox values, so the test suite
sees the gateway as available:

```xml
<env name="PAYMENT_STRIPE_ENABLED" value="true"/>
<env name="PAYMENT_STRIPE_SECRET_KEY" value="sk_test_suite"/>
```

If you cache config in production, run `php artisan config:cache` after
changing `.env`.

---

## Step 4: See it appear

With no further code, the gateway is now live everywhere a gateway is used:

```sh
curl -sk https://order-os.ddev.site/api/v1/payment-gateways -H "Authorization: Bearer $TOKEN"
```

```json
{
  "data": [
    { "key": "credit_card", "name": "Credit Card", "available": true, "fields": [ ... ] },
    { "key": "paypal", "name": "PayPal", "available": true, "fields": [ ... ] },
    { "key": "bank_transfer", "name": "Bank Transfer", "available": true, "fields": [ ... ] },
    { "key": "stripe", "name": "Stripe", "available": true,
      "fields": [ { "name": "payment_method", "required": true } ] }
  ]
}
```

- **Validation**: `POST /orders/{id}/payments` now accepts `"method": "stripe"`
  and validates `details.payment_method` with your rules. A missing token is a
  `422` on `details.payment_method`, reported as "payment method".
- **Availability**: remove `PAYMENT_STRIPE_SECRET_KEY` and the list shows
  `"available": false`. The method is then refused at validation, and its
  error message lists the methods that *are* available.
- **Filters**: `GET /payments?method=stripe` works.

Pay the seeded unpaid order (#3) with Stripe's test token:

```sh
curl -sk -X POST https://order-os.ddev.site/api/v1/orders/3/payments \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: try-stripe-0001" \
  -d '{"method":"stripe","details":{"payment_method":"pm_card_visa"}}'
```

With a real Stripe test key, `pm_card_visa` succeeds (`201`) and
`pm_card_chargeDeclinedInsufficientFunds` declines (`402`,
`failure.code: "insufficient_funds"`).

### Optional: override it in the database

Switch the gateway or rotate its key without touching `.env` or deploying:

```sh
ddev artisan payments:gateway stripe --credential=secret_key=sk_live_...
ddev artisan payments:gateway stripe --setting=mode=live
ddev artisan payments:gateway stripe --disable
```

The row in `payment_gateway_settings` lays over the config per name. A rotated
`secret_key` keeps `publishable_key` from `.env`, and credentials are stored
encrypted. The override can change `enabled`, credentials and settings, but
never the `driver`: that comes only from the config file.

---

## Step 5: Test it

Make both tests in `Modules/Payments/tests/`. The module's `composer.json`
already maps `Modules\Payments\Tests\` there, and the `Modules` suite in
`phpunit.xml` picks them up.

### Unit: every outcome of `charge()`

`Modules/Payments/tests/Unit/StripeGatewayTest.php`. It builds the gateway
the same way the registry does, through the container with a `config` by
name, and fakes Stripe with `Http::fake()`.

```php
<?php

namespace Modules\Payments\Tests\Unit;

use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Gateways\StripeGateway;
use Modules\Payments\Support\GatewayConfig;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
{
    private function gateway(array $credentials = ['secret_key' => 'sk_test_suite']): StripeGateway
    {
        return $this->app->make(StripeGateway::class, ['config' => new GatewayConfig(
            key: 'stripe',
            driver: StripeGateway::class,
            name: 'Stripe',
            enabled: true,
            credentials: $credentials,
            settings: ['base_url' => 'https://api.stripe.com'],
        )]);
    }

    private function chargeRequest(): ChargeRequest
    {
        return new ChargeRequest(
            paymentId: 7,
            orderId: 42,
            amount: '60.99',
            currency: 'USD',
            customerName: 'Jane Doe',
            customerEmail: 'jane@example.com',
            details: ['payment_method' => 'pm_card_visa'],
        );
    }

    public function test_a_succeeded_intent_is_a_successful_charge(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'status' => 'succeeded'])]);

        $result = $this->gateway()->charge($this->chargeRequest());

        $this->assertSame(PaymentStatus::Successful, $result->status);
        $this->assertSame('pi_123', $result->transactionId);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && $request->hasHeader('Authorization', 'Bearer sk_test_suite')
            && $request->hasHeader('Idempotency-Key', 'order-os-payment-7')
            && $request['amount'] == 6099
            && $request['currency'] === 'usd');
    }

    public function test_a_card_error_is_a_failed_result_not_an_exception(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
            'payment_intent' => ['id' => 'pi_declined'],
        ]], 402)]);

        $result = $this->gateway()->charge($this->chargeRequest());

        $this->assertSame(PaymentStatus::Failed, $result->status);
        $this->assertSame('insufficient_funds', $result->failureCode);
        $this->assertSame('pi_declined', $result->transactionId);
    }

    public function test_an_intent_that_needs_3ds_is_pending(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_3ds', 'status' => 'requires_action'])]);

        $this->assertSame(PaymentStatus::Pending, $this->gateway()->charge($this->chargeRequest())->status);
    }

    public function test_a_server_error_throws_as_an_unknown_outcome(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->expectException(RequestException::class);

        $this->gateway()->charge($this->chargeRequest());
    }

    public function test_a_refused_connection_is_unreachable(): void
    {
        // Http::failedConnection() rejects with Guzzle's ConnectException.
        Http::fake(['api.stripe.com/*' => Http::failedConnection()]);

        $this->expectException(GatewayUnreachable::class);

        $this->gateway()->charge($this->chargeRequest());
    }

    public function test_a_timeout_is_not_unreachable_because_the_charge_may_have_landed(): void
    {
        Http::fake(['api.stripe.com/*' => fn (Request $request) => Create::rejectionFor(new NetworkTimeoutException(
            'cURL error 28: Operation timed out after 15000 milliseconds',
            $request->toPsrRequest(),
        ))]);

        try {
            $this->gateway()->charge($this->chargeRequest());
            $this->fail('Expected an exception.');
        } catch (GatewayUnreachable) {
            $this->fail('A timeout must never be reported as unreachable.');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_it_is_unavailable_without_a_secret_key(): void
    {
        $this->assertFalse($this->gateway(credentials: [])->isAvailable());
    }
}
```

### Feature: through the real endpoint

`Modules/Payments/tests/Feature/StripePaymentTest.php`. It goes through the
whole HTTP stack: JWT auth, the form request merging your `rules()`, the
service, and the resource.

```php
<?php

namespace Modules\Payments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Models\User;
use Modules\Orders\Models\Order;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Tests\TestCase;

class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_an_order_can_be_paid_with_stripe(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'status' => 'succeeded'])]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->withItems()->create();   // total 60.99

        $this->withToken($this->token($user))
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'details' => ['payment_method' => 'pm_card_visa'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.method', 'stripe')
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', '60.99')
            ->assertJsonPath('data.transaction_id', 'pi_123');
    }

    public function test_an_unreachable_stripe_fails_the_payment_so_it_can_be_retried(): void
    {
        Http::fake(['api.stripe.com/*' => Http::failedConnection()]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->withItems()->create();

        $this->withToken($this->token($user))
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'details' => ['payment_method' => 'pm_card_visa'],
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'gateway_unreachable')
            ->assertJsonPath('context.payment_status', 'failed');

        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
    }

    public function test_a_stripe_server_error_leaves_the_payment_pending(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->withItems()->create();

        $this->withToken($this->token($user))
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'details' => ['payment_method' => 'pm_card_visa'],
            ])
            ->assertStatus(502)
            ->assertJsonPath('code', 'gateway_error')
            ->assertJsonPath('context.payment_status', 'pending');

        $this->assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
    }

    public function test_stripe_details_are_validated_before_stripe_is_called(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->withItems()->create();

        $this->withToken($this->token($user))
            ->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'stripe', 'details' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['details.payment_method']);

        Http::assertNothingSent();
    }

    public function test_stripe_is_listed(): void
    {
        $this->withToken($this->token(User::factory()->create()))
            ->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertJsonFragment(['key' => 'stripe', 'name' => 'Stripe', 'available' => true]);
    }

    public function test_a_switched_off_gateway_is_refused(): void
    {
        config(['payments.gateways.stripe.enabled' => false]);

        $user = User::factory()->create();
        $order = Order::factory()->for($user)->confirmed()->withItems()->create();

        $this->withToken($this->token($user))
            ->postJson("/api/v1/orders/{$order->id}/payments", [
                'method' => 'stripe',
                'details' => ['payment_method' => 'pm_card_visa'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }
}
```

Run them:

```sh
ddev exec ./vendor/bin/phpunit --testsuite=Modules --filter=Stripe
```

What you don't need to test again: the order-state rules (confirmed only, no
double payment, retry after failure), idempotency, the 201/402/200 status
mapping, and what a 502 does to the payment. They live in `PaymentService`
and the controller, and hold for every gateway. Test what *your* class
decides: how vendor answers map to the three results, which failures it calls
unreachable (and that a timeout is **not** one of them), what it sends, and
when it counts as available. The two feature tests for the 502s are there to
prove your exceptions land on the right side of that line end to end.

---

## The contract rules

These are not enforced by the type system, so they are written down. Every
gateway must keep them.

1. **Never put a card number, a CVC or a secret in `meta`.** `meta` is stored
   in plain JSON and returned to the client as `details`. Brand and last four
   are fine, and masked emails are fine. If you need the full value, use it
   inside `charge()` and let it go.
2. **A decline is a result, not an exception.** "Card declined",
   "insufficient funds", "payer cancelled": return `ChargeResult::failed()`
   with a stable, snake_case `code`. The client gets `402` with the failed
   payment and can retry.
3. **An exception means "I don't have an answer", and only `GatewayUnreachable`
   means "I never asked".** Throw `GatewayUnreachable` only when the request
   provably never left (DNS, connection refused, TLS handshake, an open
   circuit breaker). The payment is then failed, its Idempotency-Key released,
   and the client can retry. Let everything else throw as it is: timeouts,
   vendor 5xx, an invalid API key. `PaymentService` reports it, keeps the
   payment **`pending`** with code `gateway_error`, and answers `502`, so
   nobody pays twice for a charge that may have gone through. Never
   catch-and-return-failed for these, and never map a timeout to
   `GatewayUnreachable`. Laravel's `ConnectionException` covers read timeouts
   too, so inspect the Guzzle cause, as `neverSent()` does above.
4. **Use `pending` when the money arrives later.** Redirect flows, 3-D Secure,
   bank transfers. A pending payment blocks further payments on the order
   until something settles it, which is what stops a double charge.
5. **The key is forever.** It is the `method` clients send and the value stored
   on every payment row. Renaming it orphans history and breaks every
   client's `?method=` filter. To rename the display name, change `name`.
6. **Never trust or read the amount from `details`.** Charge
   `$request->amount` in `$request->currency`. The form request already
   prohibits `amount` in the body.
7. **Declare every `details` field you read in `rules()`.** Undeclared keys are
   stripped from the validated data once a gateway declares any rules, so
   `detail('x')` returns `null` for a field you forgot. Rules also drive
   `fields` in the gateway list. A field shows as `required: true` only when
   its rules contain the literal string `'required'`. A `Rule::requiredIf(...)`
   object is enforced, but listed as optional.
8. **Make vendor calls idempotent.** `$request->paymentId` is unique per
   attempt. Send it as the vendor's idempotency key so that a retried HTTP
   call can't charge twice.
9. **Secrets come from `credentials`, read with `$this->config->credential()`.**
   Never from `env()` inside the class: `env()` returns `null` once config is
   cached, and it bypasses the database override.

---

## Not extending `AbstractGateway`

The registry only insists on the `PaymentGateway` interface. Implement it
directly when the base class's model doesn't fit: say, availability comes from
a health-check endpoint, or the key and name are fixed by a vendor package.

```php
namespace Modules\Payments\Gateways;

use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Support\GatewayConfig;

final class CashOnDeliveryGateway implements PaymentGateway
{
    public function __construct(private readonly GatewayConfig $config) {}

    public function key(): string { return $this->config->key; }
    public function name(): string { return $this->config->name; }
    public function isAvailable(): bool { return $this->config->enabled; }
    public function rules(): array { return []; }

    public function charge(ChargeRequest $request): ChargeResult
    {
        // Collected by the courier; settled when they report back.
        return ChargeResult::pending('COD-'.$request->orderId.'-'.$request->paymentId);
    }
}
```

Two things to keep if you do:

- **Still accept `GatewayConfig $config`**, named `config`, because that is
  how the registry passes it. Return `$this->config->key` from `key()` rather
  than a literal, so that the config array key and the stored method can't
  drift apart.
- **`isAvailable()` is called on every gateway listing and on every payment
  request's validation.** Keep it cheap and side-effect free. No network
  calls: cache a health check elsewhere if you need one.

---

## Checklist

- [ ] `Modules/Payments/app/Gateways/<Name>Gateway.php` extends
      `AbstractGateway` or implements `PaymentGateway`
- [ ] The constructor's config parameter is named `$config`, and
      `parent::__construct($config)` is called
- [ ] `rules()` declares every `details` field `charge()` reads
- [ ] `charge()` returns `successful` / `pending` / `failed` for every vendor
      answer, and throws only when it has no answer
- [ ] `GatewayUnreachable` is thrown only for failures that provably happened
      before the request left. A timeout is never one of them
- [ ] `meta` holds nothing sensitive (no PAN, CVC, token secret or API key)
- [ ] The amount comes from `$request->amount`, converted to the vendor's unit
      with `Money` if needed
- [ ] The vendor idempotency key is derived from `$request->paymentId`
- [ ] `requiredCredentials()` / `requiredSettings()` list what availability
      depends on
- [ ] The config block is in `Modules/Payments/config/config.php` with a key
      you'll never rename, every secret is from `env()`, and `enabled` defaults
      to `false`
- [ ] The keys are in `.env.example` (placeholders), `.env`, and `phpunit.xml`
- [ ] It shows in `GET /api/v1/payment-gateways` with `available: true`
- [ ] Unit tests cover success, decline, pending (if the vendor has it),
      vendor error, unreachable versus timeout, and unavailability. A feature
      test pays an order through `POST /api/v1/orders/{id}/payments`
- [ ] Optional: failure messages in `Modules/Payments/lang/en/gateways.php`

Nothing else in the codebase should be in your diff. If it is, the contract
has a gap. Fix the gap rather than special-casing the gateway.
