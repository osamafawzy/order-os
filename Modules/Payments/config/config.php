<?php

use Modules\Payments\Gateways\BankTransferGateway;
use Modules\Payments\Gateways\CreditCardGateway;
use Modules\Payments\Gateways\PaypalGateway;

$bool = static fn (string $key, bool $default): bool => filter_var(env($key, $default), FILTER_VALIDATE_BOOL);

return [
    'name' => 'Payments',

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | One entry per gateway. The array key is the `method` clients send.
    |
    |   driver       the PaymentGateway class — resolved through the container,
    |                so its constructor may type-hint any other dependency
    |   name         shown by GET /api/v1/payment-gateways
    |   enabled      master switch
    |   credentials  secrets; every value comes from .env, never from code
    |   settings     non-secret options
    |
    | This array is the SEED. A row in payment_gateway_settings with the same
    | key overrides `enabled`, and individual credentials and settings, so a
    | gateway can be switched off or have its key rotated without a deploy.
    | The driver class can only come from here: a database row that could
    | name an arbitrary class would be a remote-code-execution switch.
    |
    | Adding a gateway: write the class, add an entry here, add its .env keys.
    | See docs/ADDING_A_PAYMENT_GATEWAY.md.
    |
    */
    'gateways' => [
        'credit_card' => [
            'driver' => CreditCardGateway::class,
            'name' => 'Credit Card',
            'enabled' => $bool('PAYMENT_CREDIT_CARD_ENABLED', true),
            'credentials' => [
                'public_key' => env('PAYMENT_CREDIT_CARD_PUBLIC_KEY'),
                'secret_key' => env('PAYMENT_CREDIT_CARD_SECRET_KEY'),
            ],
            'settings' => [
                'mode' => env('PAYMENT_CREDIT_CARD_MODE', 'sandbox'),
            ],
        ],

        'paypal' => [
            'driver' => PaypalGateway::class,
            'name' => 'PayPal',
            'enabled' => $bool('PAYMENT_PAYPAL_ENABLED', true),
            'credentials' => [
                'client_id' => env('PAYMENT_PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYMENT_PAYPAL_CLIENT_SECRET'),
            ],
            'settings' => [
                'mode' => env('PAYMENT_PAYPAL_MODE', 'sandbox'),
            ],
        ],

        'bank_transfer' => [
            'driver' => BankTransferGateway::class,
            'name' => 'Bank Transfer',
            'enabled' => $bool('PAYMENT_BANK_TRANSFER_ENABLED', true),
            'credentials' => [],
            'settings' => [
                'beneficiary' => env('PAYMENT_BANK_TRANSFER_BENEFICIARY', 'Order OS Ltd.'),
                'iban' => env('PAYMENT_BANK_TRANSFER_IBAN'),
                'bank_name' => env('PAYMENT_BANK_TRANSFER_BANK_NAME', 'Example Bank'),
            ],
        ],
    ],
];
