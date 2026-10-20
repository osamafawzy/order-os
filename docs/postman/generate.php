<?php

/**
 * Builds the Postman collection from the running code.
 *
 *     ddev exec php docs/postman/generate.php
 *
 * Every request below is sent through the real HTTP kernel, in order, against
 * a throwaway in-memory SQLite database, and the response that comes back is
 * saved as that request's example. The examples in the collection therefore
 * cannot drift from what the API actually returns: regenerate after changing
 * an endpoint and the documentation follows.
 *
 * Each request also gets pm.test assertions on its status code (and error
 * code, where it has one), so the same file is a contract test:
 *
 *     postman collection run docs/postman/Order-OS.postman_collection.json \
 *         -e docs/postman/Order-OS.postman_environment.json
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

// Isolated from the development database, whatever .env says.
foreach ([
    'APP_ENV' => 'local',
    'APP_URL' => 'https://order-os.ddev.site',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('core.rate_limit_per_minute', 1000);

Artisan::call('migrate', ['--force' => true]);

$baseUrl = 'https://order-os.ddev.site/api/v1';
$card = static fn (string $number, array $extra = []): array => [
    'method' => 'credit_card',
    'details' => array_merge([
        'card_number' => $number,
        'exp_month' => 12,
        'exp_year' => (int) date('Y') + 2,
        'cvc' => '123',
        'holder_name' => 'Jane Tester',
    ], $extra),
];
$newOrder = static fn (string $name): array => [
    'customer_name' => $name,
    'customer_email' => 'customer@example.com',
    'items' => [
        ['product_name' => 'Noise-cancelling Headphones', 'quantity' => 1, 'price' => '199.00'],
        ['product_name' => 'Carrying Case', 'quantity' => 1, 'price' => '24.50'],
    ],
];

// Values the scripts would generate at run time, fixed here so the
// examples are readable.
$vars = [
    'baseUrl' => $baseUrl,
    'userEmail' => 'jane.tester@example.com',
    'userPassword' => 'Secret123',
    'idempotencyKey' => 'b7e9c2d4-5f1a-4c3e-9d8b-2a6f0e1c7d35',
];

/*
|--------------------------------------------------------------------------
| The requests
|--------------------------------------------------------------------------
|
| expect   status code asserted by pm.test
| code     the error `code` asserted, for error cases
| capture  collection variables set from the JSON response (dot paths)
| pre      a pre-request script, as lines of JS
|
*/
$folders = [
    [
        'name' => 'Authentication',
        'description' => "Register, log in and manage the JWT.\n\nRegister and Login return an `access_token`; the test scripts store it in the `token` collection variable, and every other request sends it as `Authorization: Bearer {{token}}`. Both endpoints are rate-limited per email and per IP (`429` with `Retry-After` when exceeded).",
        'items' => [
            [
                'name' => 'Register',
                'method' => 'POST', 'path' => '/auth/register', 'auth' => false,
                'body' => ['name' => 'Jane Tester', 'email' => '{{userEmail}}', 'password' => '{{userPassword}}', 'password_confirmation' => '{{userPassword}}'],
                'pre' => [
                    '// A fresh address every run, so the collection can be run repeatedly.',
                    "pm.collectionVariables.set('userEmail', 'jane.' + Date.now() + '@example.com');",
                ],
                'expect' => 201, 'capture' => ['token' => 'data.access_token'],
                'description' => "Create an account and receive a token straight away.\n\nPassword: at least 8 characters with letters and numbers, confirmed by `password_confirmation`. Emails are stored lower-cased and must be unique.",
            ],
            [
                'name' => 'Register — validation error',
                'method' => 'POST', 'path' => '/auth/register', 'auth' => false,
                'body' => ['name' => '', 'email' => '{{userEmail}}', 'password' => 'short', 'password_confirmation' => 'different'],
                'expect' => 422,
                'description' => 'Every invalid field is reported under `errors`, keyed by field name. Here: missing name, an email already taken, and a weak, unconfirmed password.',
            ],
            [
                'name' => 'Me',
                'method' => 'GET', 'path' => '/auth/me',
                'expect' => 200,
                'description' => 'The user the bearer token belongs to.',
            ],
            [
                'name' => 'Refresh token',
                'method' => 'POST', 'path' => '/auth/refresh',
                'expect' => 200, 'capture' => ['token' => 'data.access_token'],
                'description' => 'Exchange the current token for a new one. The old token is blacklisted at the same moment and stops working.',
            ],
            [
                'name' => 'Logout',
                'method' => 'POST', 'path' => '/auth/logout',
                'expect' => 204,
                'description' => 'Revoke the current token. It is refused for the rest of its lifetime.',
            ],
            [
                'name' => 'Me — revoked token',
                'method' => 'GET', 'path' => '/auth/me',
                'expect' => 401, 'code' => 'unauthenticated',
                'description' => 'The token logged out above is now rejected. A missing, malformed or expired token gets the same answer.',
            ],
            [
                'name' => 'Login — invalid credentials',
                'method' => 'POST', 'path' => '/auth/login', 'auth' => false,
                'body' => ['email' => '{{userEmail}}', 'password' => 'WrongPassword1'],
                'expect' => 401, 'code' => 'invalid_credentials',
                'description' => 'One answer for an unknown email and a wrong password alike, so the endpoint cannot be used to find out who has an account.',
            ],
            [
                'name' => 'Login',
                'method' => 'POST', 'path' => '/auth/login', 'auth' => false,
                'body' => ['email' => '{{userEmail}}', 'password' => '{{userPassword}}'],
                'expect' => 200, 'capture' => ['token' => 'data.access_token'],
                'description' => "Exchange credentials for a token. `expires_in` is in seconds (`JWT_TTL`, default 60 minutes).\n\nThe seeded demo account is `demo@order-os.test` / `password123`.",
            ],
        ],
    ],
    [
        'name' => 'Payment Gateways',
        'description' => 'Which payment methods exist and what each needs. Driven entirely by `config/payments` (plus database overrides) — a newly added gateway appears here with no other change.',
        'items' => [
            [
                'name' => 'List payment gateways',
                'method' => 'GET', 'path' => '/payment-gateways',
                'expect' => 200,
                'description' => 'Every configured gateway: its `key` (the `method` to send when paying), whether it is `available` right now (enabled and fully configured), and the `details` fields it expects. Credentials are never returned.',
            ],
        ],
    ],
    [
        'name' => 'Orders',
        'description' => "Orders belong to the authenticated user; another user's order answers `404` exactly as a missing one does.\n\nStatus machine: `pending → confirmed → cancelled`, or `pending → cancelled`. Only pending orders can be edited; a confirmed order's items and total are locked because that total is what a payment charges. Totals are always computed by the server.",
        'items' => [
            [
                'name' => 'Create order',
                'method' => 'POST', 'path' => '/orders',
                'body' => [
                    'customer_name' => 'John Smith',
                    'customer_email' => 'john.smith@example.com',
                    'customer_phone' => '+1 555 0100',
                    'items' => [
                        ['product_name' => 'Wireless Mouse', 'quantity' => 2, 'price' => '25.50'],
                        ['product_name' => 'USB-C Cable', 'quantity' => 3, 'price' => '9.99'],
                    ],
                    'notes' => 'Leave at the front desk.',
                ],
                'expect' => 201, 'capture' => ['orderId' => 'data.id'],
                'description' => 'Creates a **pending** order. `line_total` and `total` are calculated server-side in integer cents; `status` and `total` may not be sent. Prices are decimal strings or numbers with at most two decimal places. Returns a `Location` header.',
            ],
            [
                'name' => 'Create order — validation error',
                'method' => 'POST', 'path' => '/orders',
                'body' => [
                    'customer_name' => 'J',
                    'customer_email' => 'not-an-email',
                    'status' => 'confirmed',
                    'items' => [
                        ['product_name' => 'Wireless Mouse', 'quantity' => 0, 'price' => '25.505'],
                    ],
                ],
                'expect' => 422,
                'description' => 'Item errors name the item by position ("price of item #1"), so a client can point at the right row.',
            ],
            [
                'name' => 'List orders',
                'method' => 'GET', 'path' => '/orders', 'query' => ['per_page' => '10', 'page' => '1'],
                'expect' => 200, 'paginated' => true,
                'description' => "The user's orders, newest first. Paginated: `per_page` 1–100 (default 15) and `page`; the response carries `links` and `meta`.",
            ],
            [
                'name' => 'List orders — filter by status',
                'method' => 'GET', 'path' => '/orders', 'query' => ['status' => 'pending'],
                'expect' => 200, 'paginated' => true,
                'description' => '`status` is one of `pending`, `confirmed`, `cancelled`.',
            ],
            [
                'name' => 'List orders — invalid filter',
                'method' => 'GET', 'path' => '/orders', 'query' => ['status' => 'shipped', 'per_page' => '500'],
                'expect' => 422,
                'description' => 'Unknown statuses and out-of-range page sizes are rejected rather than silently ignored.',
            ],
            [
                'name' => 'Get order',
                'method' => 'GET', 'path' => '/orders/{{orderId}}',
                'expect' => 200,
            ],
            [
                'name' => 'Get order — not found',
                'method' => 'GET', 'path' => '/orders/999999',
                'expect' => 404, 'code' => 'resource_not_found',
                'description' => 'Also the answer for an order that belongs to someone else.',
            ],
            [
                'name' => 'Update order',
                'method' => 'PATCH', 'path' => '/orders/{{orderId}}',
                'body' => [
                    'customer_phone' => '+1 555 0199',
                    'items' => [
                        ['product_name' => 'Wireless Mouse', 'quantity' => 1, 'price' => '25.50'],
                        ['product_name' => 'Mechanical Keyboard', 'quantity' => 1, 'price' => '89.90'],
                    ],
                ],
                'expect' => 200,
                'description' => 'Partial update — `PATCH` only; `PUT` is not offered (`405`), because this endpoint never replaces the whole order. Only the fields sent change; `items`, when sent, replaces the whole list and the total is recalculated. Allowed only while the order is **pending**.',
            ],
            [
                'name' => 'Confirm order',
                'method' => 'PATCH', 'path' => '/orders/{{orderId}}',
                'body' => ['status' => 'confirmed'],
                'expect' => 200,
                'description' => 'Moves the order to `confirmed`, which locks it and makes it payable. Detail changes may be sent in the same request; they are applied first.',
            ],
            [
                'name' => 'Update order — not editable',
                'method' => 'PATCH', 'path' => '/orders/{{orderId}}',
                'body' => ['customer_name' => 'Johnny Smith'],
                'expect' => 409, 'code' => 'order_not_editable',
                'description' => 'A confirmed order can only change status (to `cancelled`).',
            ],
            [
                'name' => 'Update order — invalid status transition',
                'method' => 'PATCH', 'path' => '/orders/{{orderId}}',
                'body' => ['status' => 'pending'],
                'expect' => 422, 'code' => 'invalid_status_transition',
                'description' => '`context.allowed` lists the statuses this order can move to.',
            ],
            [
                'name' => 'Create order (to delete)',
                'method' => 'POST', 'path' => '/orders',
                'body' => ['customer_name' => 'Temporary Customer', 'customer_email' => 'temp@example.com', 'items' => [['product_name' => 'Sample', 'quantity' => 1, 'price' => '5.00']]],
                'expect' => 201, 'capture' => ['disposableOrderId' => 'data.id'],
            ],
            [
                'name' => 'Delete order',
                'method' => 'DELETE', 'path' => '/orders/{{disposableOrderId}}',
                'expect' => 204,
                'description' => 'Allowed only when the order has no payments at all — see *Payments › Business rules*.',
            ],
            [
                'name' => 'Get order — deleted',
                'method' => 'GET', 'path' => '/orders/{{disposableOrderId}}',
                'expect' => 404, 'code' => 'resource_not_found',
            ],
        ],
    ],
    [
        'name' => 'Payments',
        'description' => "`POST /orders/{id}/payments` charges the order's own total through the gateway named by `method`. The body never contains an amount. Gateway-specific fields go in `details` — see *Payment Gateways* for what each method needs.\n\nResponses: **201** successful or pending, **402** declined (the body is still the payment, with `failure`), **200** an `Idempotency-Key` replay. A failed payment can be retried; a pending or successful one blocks further attempts.\n\nTest values (simulated gateways): card `4242424242424242` approves, `4000000000000002` declines, `4000000000009995` has insufficient funds; a PayPal email starting with `declined` is refused; bank transfer always returns `pending` with instructions.",
        'folders' => [
            [
                'name' => 'Credit card',
                'items' => [
                    [
                        'name' => 'Pay — card declined',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'body' => $card('4000000000000002'),
                        'expect' => 402, 'capture' => ['failedPaymentId' => 'data.id'],
                        'description' => 'A decline is a result, not an error: the payment is stored as `failed` with a `failure` code and message, and the order can be paid again.',
                    ],
                    [
                        'name' => 'Pay — validation error',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'body' => ['method' => 'credit_card', 'details' => ['card_number' => '4242424242424241', 'exp_month' => 13, 'exp_year' => 2020]],
                        'expect' => 422,
                        'description' => 'The rules under `details` come from the gateway itself. Card numbers are Luhn-checked before any gateway is called.',
                    ],
                    [
                        'name' => 'Pay — unsupported method',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'body' => ['method' => 'bitcoin', 'amount' => '1.00'],
                        'expect' => 422,
                        'description' => 'Only available gateways are accepted, and the message lists them. An `amount` is refused: the order total is always charged.',
                    ],
                    [
                        'name' => 'Pay — successful',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'headers' => ['Idempotency-Key' => '{{idempotencyKey}}'],
                        'body' => $card('4242424242424242'),
                        'pre' => [
                            '// One key per logical attempt. Resending with the same key is safe.',
                            "pm.collectionVariables.set('idempotencyKey', pm.variables.replaceIn('{{\$guid}}'));",
                        ],
                        'expect' => 201, 'capture' => ['paymentId' => 'data.id'],
                        'description' => "Approved. Only the card brand and last four digits are stored — never the number or CVC.\n\n`Idempotency-Key` (optional, 8–255 chars) makes the request safe to retry after a timeout.",
                    ],
                    [
                        'name' => 'Pay — idempotent replay',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'headers' => ['Idempotency-Key' => '{{idempotencyKey}}'],
                        'body' => $card('4242424242424242'),
                        'expect' => 200, 'replayed' => true,
                        'description' => 'The same key again returns the original payment with `200` and `Idempotent-Replayed: true`. Nothing is charged twice. Reusing a key for a different order is a `422` (`idempotency_key_reused`).',
                    ],
                    [
                        'name' => 'Pay — order already paid',
                        'method' => 'POST', 'path' => '/orders/{{orderId}}/payments',
                        'body' => $card('4242424242424242'),
                        'expect' => 409, 'code' => 'order_already_paid',
                    ],
                ],
            ],
            [
                'name' => 'PayPal',
                'items' => [
                    [
                        'name' => 'Setup — create order',
                        'method' => 'POST', 'path' => '/orders',
                        'body' => $newOrder('Paula Pal'),
                        'expect' => 201, 'capture' => ['paypalOrderId' => 'data.id'],
                    ],
                    [
                        'name' => 'Setup — confirm order',
                        'method' => 'PATCH', 'path' => '/orders/{{paypalOrderId}}',
                        'body' => ['status' => 'confirmed'],
                        'expect' => 200,
                    ],
                    [
                        'name' => 'Pay — PayPal declined',
                        'method' => 'POST', 'path' => '/orders/{{paypalOrderId}}/payments',
                        'body' => ['method' => 'paypal', 'details' => ['paypal_email' => 'declined.buyer@example.com']],
                        'expect' => 402,
                    ],
                    [
                        'name' => 'Pay — PayPal successful',
                        'method' => 'POST', 'path' => '/orders/{{paypalOrderId}}/payments',
                        'body' => ['method' => 'paypal', 'details' => ['paypal_email' => 'paula.buyer@example.com']],
                        'expect' => 201,
                        'description' => 'The payer email is stored masked.',
                    ],
                ],
            ],
            [
                'name' => 'Bank transfer',
                'items' => [
                    [
                        'name' => 'Setup — create order',
                        'method' => 'POST', 'path' => '/orders',
                        'body' => $newOrder('Bernard Bank'),
                        'expect' => 201, 'capture' => ['bankOrderId' => 'data.id'],
                    ],
                    [
                        'name' => 'Setup — confirm order',
                        'method' => 'PATCH', 'path' => '/orders/{{bankOrderId}}',
                        'body' => ['status' => 'confirmed'],
                        'expect' => 200,
                    ],
                    [
                        'name' => 'Pay — bank transfer (pending)',
                        'method' => 'POST', 'path' => '/orders/{{bankOrderId}}/payments',
                        'body' => ['method' => 'bank_transfer', 'details' => ['account_holder' => 'Bernard Bank']],
                        'expect' => 201,
                        'description' => 'An offline method: the payment stays `pending` and `details.instructions` tells the customer where to send the money and which reference to quote.',
                    ],
                    [
                        'name' => 'Pay — payment in progress',
                        'method' => 'POST', 'path' => '/orders/{{bankOrderId}}/payments',
                        'body' => $card('4242424242424242'),
                        'expect' => 409, 'code' => 'payment_in_progress',
                        'description' => 'A pending payment blocks new attempts, so a card cannot be charged on top of a transfer that may still arrive.',
                    ],
                    [
                        'name' => 'Cancel order — payment pending',
                        'method' => 'PATCH', 'path' => '/orders/{{bankOrderId}}',
                        'body' => ['status' => 'cancelled'],
                        'expect' => 409, 'code' => 'payment_in_progress',
                        'description' => 'Nor can the order be cancelled while that transfer may still land.',
                    ],
                ],
            ],
            [
                'name' => 'Business rules',
                'items' => [
                    [
                        'name' => 'Setup — create pending order',
                        'method' => 'POST', 'path' => '/orders',
                        'body' => $newOrder('Penny Pending'),
                        'expect' => 201, 'capture' => ['pendingOrderId' => 'data.id'],
                    ],
                    [
                        'name' => 'Pay — order not confirmed',
                        'method' => 'POST', 'path' => '/orders/{{pendingOrderId}}/payments',
                        'body' => $card('4242424242424242'),
                        'expect' => 409, 'code' => 'order_not_confirmed',
                        'description' => 'Payments can only be processed for orders in the `confirmed` status.',
                    ],
                    [
                        'name' => 'Delete order — has payments',
                        'method' => 'DELETE', 'path' => '/orders/{{orderId}}',
                        'expect' => 409, 'code' => 'order_has_payments',
                        'description' => 'Orders with any payment — failed ones included — cannot be deleted. The foreign key enforces the same rule in the database.',
                    ],
                    [
                        'name' => 'Cancel order — already paid',
                        'method' => 'PATCH', 'path' => '/orders/{{orderId}}',
                        'body' => ['status' => 'cancelled'],
                        'expect' => 409, 'code' => 'order_already_paid',
                        'description' => 'Cancelling a paid order would need a refund, which is out of scope, so it is refused.',
                    ],
                ],
            ],
            [
                'name' => 'View payments',
                'items' => [
                    [
                        'name' => 'List payments',
                        'method' => 'GET', 'path' => '/payments', 'query' => ['per_page' => '10'],
                        'expect' => 200, 'paginated' => true,
                        'description' => "All of the user's payments, newest first, paginated like orders.",
                    ],
                    [
                        'name' => 'List payments — filtered',
                        'method' => 'GET', 'path' => '/payments', 'query' => ['status' => 'failed', 'method' => 'credit_card'],
                        'expect' => 200, 'paginated' => true,
                        'description' => 'Filters: `status` (`pending`, `successful`, `failed`), `method` (any configured gateway key) and `order_id`.',
                    ],
                    [
                        'name' => 'List payments for an order',
                        'method' => 'GET', 'path' => '/orders/{{orderId}}/payments',
                        'expect' => 200, 'paginated' => true,
                        'description' => 'Every attempt for one order, declined ones included. Accepts the same `status`/`method` filters.',
                    ],
                    [
                        'name' => 'Get payment',
                        'method' => 'GET', 'path' => '/payments/{{paymentId}}',
                        'expect' => 200,
                    ],
                    [
                        'name' => 'Get payment — not found',
                        'method' => 'GET', 'path' => '/payments/999999',
                        'expect' => 404, 'code' => 'resource_not_found',
                    ],
                ],
            ],
        ],
    ],
];

/*
|--------------------------------------------------------------------------
| Run them, and build the collection
|--------------------------------------------------------------------------
*/
$kernel = $app->make(Kernel::class);
$fill = static fn (string $value, array $vars): string => preg_replace_callback(
    '/\{\{(\w+)\}\}/',
    static fn (array $m): string => (string) ($vars[$m[1]] ?? $m[0]),
    $value,
);

$send = static function (array $item) use ($app, $kernel, &$vars, $fill): array {
    $query = isset($item['query']) ? '?'.http_build_query($item['query']) : '';
    $body = isset($item['body']) ? $fill(json_encode($item['body'], JSON_UNESCAPED_SLASHES), $vars) : null;

    $server = ['HTTP_ACCEPT' => 'application/json', 'HTTPS' => 'on'];
    if ($body !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    if (($item['auth'] ?? true) && isset($vars['token'])) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$vars['token'];
    }
    foreach ($item['headers'] ?? [] as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $fill($value, $vars);
    }

    $request = Request::create($fill($vars['baseUrl'].$item['path'], $vars).$query, $item['method'], [], [], [], $server, $body);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    // A fresh guard and fresh scoped services per request, as a real
    // server process would have.
    $app['auth']->forgetGuards();
    $app->forgetScopedInstances();

    $json = json_decode((string) $response->getContent(), true);

    foreach ($item['capture'] ?? [] as $var => $path) {
        $vars[$var] = Arr::get($json ?? [], $path);
    }

    if ($response->getStatusCode() !== $item['expect']) {
        fwrite(STDERR, sprintf("  ! %s: expected %d, got %d\n%s\n", $item['name'], $item['expect'], $response->getStatusCode(), $response->getContent()));
        exit(1);
    }

    return [$response, $json];
};

$reasons = [200 => 'OK', 201 => 'Created', 204 => 'No Content', 401 => 'Unauthorized', 402 => 'Payment Required', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Content'];

$buildRequest = static function (array $item): array {
    $headers = [['key' => 'Accept', 'value' => 'application/json']];
    if (isset($item['body'])) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
    }
    foreach ($item['headers'] ?? [] as $name => $value) {
        $headers[] = ['key' => $name, 'value' => $value];
    }

    $segments = array_values(array_filter(explode('/', $item['path'])));
    $raw = '{{baseUrl}}'.$item['path'].(isset($item['query']) ? '?'.http_build_query($item['query']) : '');

    $request = [
        'method' => $item['method'],
        'header' => $headers,
        'url' => array_filter([
            'raw' => $raw,
            'host' => ['{{baseUrl}}'],
            'path' => $segments,
            'query' => isset($item['query']) ? array_map(
                static fn ($k, $v): array => ['key' => $k, 'value' => (string) $v],
                array_keys($item['query']),
                $item['query'],
            ) : null,
        ]),
    ];

    if (isset($item['body'])) {
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($item['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if (! ($item['auth'] ?? true)) {
        $request['auth'] = ['type' => 'noauth'];
    }

    if (isset($item['description'])) {
        $request['description'] = $item['description'];
    }

    return $request;
};

$tests = static function (array $item): array {
    $js = [sprintf("pm.test('Status code is %d', function () {", $item['expect']), sprintf('    pm.response.to.have.status(%d);', $item['expect']), '});'];

    if ($item['expect'] === 204) {
        return $js;
    }

    $js[] = '';
    $js[] = 'const json = pm.response.json();';

    if (isset($item['code'])) {
        $js[] = sprintf("pm.test('Error code is %s', function () {", $item['code']);
        $js[] = sprintf("    pm.expect(json.code).to.eql('%s');", $item['code']);
        $js[] = '});';
    } elseif ($item['expect'] === 422) {
        $js[] = "pm.test('Validation errors are listed per field', function () {";
        $js[] = "    pm.expect(json).to.have.property('errors');";
        $js[] = '});';
    } elseif (! empty($item['paginated'])) {
        $js[] = "pm.test('Response is paginated', function () {";
        $js[] = "    pm.expect(json).to.have.property('data').that.is.an('array');";
        $js[] = "    pm.expect(json.meta).to.include.keys('current_page', 'per_page', 'total', 'last_page');";
        $js[] = '});';
    } else {
        $js[] = "pm.test('Response has data', function () {";
        $js[] = "    pm.expect(json).to.have.property('data');";
        $js[] = '});';
    }

    if (! empty($item['replayed'])) {
        $js[] = "pm.test('Marked as an idempotent replay', function () {";
        $js[] = "    pm.response.to.have.header('Idempotent-Replayed', 'true');";
        $js[] = '});';
    }

    foreach ($item['capture'] ?? [] as $var => $path) {
        $js[] = sprintf("pm.collectionVariables.set('%s', json.%s);", $var, $path);
    }

    return $js;
};

$buildItem = static function (array $item) use ($send, $buildRequest, $tests, $reasons): array {
    [$response, $json] = $send($item);
    $request = $buildRequest($item);

    $responseHeaders = [['key' => 'Content-Type', 'value' => 'application/json']];
    foreach (['Location', 'Idempotent-Replayed'] as $header) {
        if ($response->headers->has($header)) {
            $responseHeaders[] = ['key' => $header, 'value' => (string) $response->headers->get($header)];
        }
    }

    $code = $response->getStatusCode();
    $exampleName = match (true) {
        $code === 204 => '204 No Content',
        default => $code.' '.($reasons[$code] ?? ''),
    };

    $events = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $tests($item)]]];
    if (isset($item['pre'])) {
        array_unshift($events, ['listen' => 'prerequest', 'script' => ['type' => 'text/javascript', 'exec' => $item['pre']]]);
    }

    return [
        'name' => $item['name'],
        'event' => $events,
        'request' => $request,
        'response' => [[
            'name' => $exampleName,
            'originalRequest' => array_diff_key($request, ['description' => true, 'auth' => true]),
            'status' => $reasons[$code] ?? '',
            'code' => $code,
            '_postman_previewlanguage' => $code === 204 ? 'text' : 'json',
            'header' => $code === 204 ? [] : $responseHeaders,
            'body' => $code === 204 ? '' : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]],
    ];
};

$buildFolder = static function (array $folder) use (&$buildFolder, $buildItem): array {
    $items = [];

    foreach ($folder['items'] ?? [] as $item) {
        $items[] = $buildItem($item);
        echo '  ✓ '.$item['name'].PHP_EOL;
    }

    foreach ($folder['folders'] ?? [] as $child) {
        $items[] = $buildFolder($child);
    }

    return array_filter([
        'name' => $folder['name'],
        'description' => $folder['description'] ?? null,
        'item' => $items,
    ]);
};

$items = array_map($buildFolder, $folders);

$collection = [
    'info' => [
        '_postman_id' => '6f1d2c3b-8a4e-4b7f-9c1d-0e5a7b3c9d21',
        'name' => 'Order OS API',
        'description' => implode("\n", [
            'An extendable order and payment management API. Laravel 13, JWT authentication, payment gateways as interchangeable strategies.',
            '',
            '## Getting started',
            '1. Import this collection and `Order-OS.postman_environment.json`, and select the environment.',
            '2. Set `baseUrl` if the API is not at `https://order-os.ddev.site/api/v1`.',
            '3. Run **Authentication › Register** (or **Login**): the token is stored automatically and sent as a Bearer token by every other request.',
            '4. Run the folders top to bottom, or the whole collection with the Runner / `postman collection run` — each request asserts its status code, so a run is a contract test.',
            '',
            '## Conventions',
            '- All bodies are JSON. Send `Accept: application/json`.',
            '- Amounts are decimal strings with two places (`"51.00"`).',
            '- Lists are paginated: `?page=` and `?per_page=` (1–100, default 15); responses carry `data`, `links` and `meta`.',
            '- Validation errors: `422` with `{ "message", "errors": { "field": ["..."] } }`.',
            '- Every other error: `{ "message", "code", "context"? }`. Branch on `code`; `message` is for people.',
            '',
            '## Error codes',
            '| HTTP | code | When |',
            '|---|---|---|',
            '| 401 | `unauthenticated` | Missing, invalid, expired or revoked token |',
            '| 401 | `invalid_credentials` | Login with a wrong email or password |',
            '| 404 | `resource_not_found` | Missing record, or one belonging to another user |',
            '| 404 | `route_not_found` | Unknown endpoint |',
            '| 405 | `method_not_allowed` | Wrong HTTP method for the endpoint |',
            '| 409 | `order_not_editable` | Editing an order that is not pending |',
            '| 409 | `order_has_payments` | Deleting an order that has payments |',
            '| 409 | `order_already_paid` | Paying or cancelling an order that is already paid |',
            '| 409 | `order_not_confirmed` | Paying an order that is not confirmed |',
            '| 409 | `payment_in_progress` | Paying or cancelling an order whose payment is still pending |',
            '| 422 | `invalid_status_transition` | A status change the order cannot make |',
            '| 422 | `unsupported_payment_method` / `payment_method_unavailable` | Gateway unknown or switched off |',
            '| 422 | `idempotency_key_reused` | An Idempotency-Key reused for a different order |',
            '| 429 | `too_many_requests` | Rate limit exceeded (see `Retry-After`) |',
            '| 500 | `server_error` | Unexpected failure (details are logged, never returned) |',
            '| 502 | `gateway_unreachable` | The provider could not be reached; nothing was sent, the payment is `failed` and a retry is safe |',
            '| 502 | `gateway_error` | The provider did not confirm the outcome; the payment is held `pending` until reconciled — do not pay again |',
        ]),
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'item' => $items,
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'variable' => array_map(
        static fn (string $key, string $value): array => ['key' => $key, 'value' => $value, 'type' => 'string'],
        ['baseUrl', 'token', 'userEmail', 'userPassword', 'idempotencyKey', 'orderId', 'disposableOrderId', 'paymentId', 'failedPaymentId', 'paypalOrderId', 'bankOrderId', 'pendingOrderId'],
        ['https://order-os.ddev.site/api/v1', '', '', 'Secret123', '', '', '', '', '', '', '', ''],
    ),
];

$environment = [
    'id' => '0c4f8e2a-3b1d-4e6f-a7c9-5d2b8e1f4a60',
    'name' => 'Order OS — local (DDEV)',
    'values' => [
        ['key' => 'baseUrl', 'value' => 'https://order-os.ddev.site/api/v1', 'type' => 'default', 'enabled' => true],
        ['key' => 'demoEmail', 'value' => 'demo@order-os.test', 'type' => 'default', 'enabled' => true],
        ['key' => 'demoPassword', 'value' => 'password123', 'type' => 'secret', 'enabled' => true],
    ],
    '_postman_variable_scope' => 'environment',
];

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents(__DIR__.'/Order-OS.postman_collection.json', json_encode($collection, $flags)."\n");
file_put_contents(__DIR__.'/Order-OS.postman_environment.json', json_encode($environment, $flags)."\n");

echo PHP_EOL.'Wrote docs/postman/Order-OS.postman_collection.json and Order-OS.postman_environment.json'.PHP_EOL;
