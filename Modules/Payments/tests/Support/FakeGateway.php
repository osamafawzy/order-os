<?php

namespace Modules\Payments\Tests\Support;

use Illuminate\Contracts\Config\Repository;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Support\GatewayConfig;

/**
 * A gateway that exists only in the test suite.
 *
 * It implements the PaymentGateway contract directly — not AbstractGateway —
 * and asks the container for an unrelated dependency, to prove that a new
 * gateway needs nothing but this class and a config entry: no change to the
 * registry, service, controller, form request or database.
 */
final class FakeGateway implements PaymentGateway
{
    public const DECLINE_TOKEN = 'tok_decline';

    /** Every charge this gateway was asked to make, in order. */
    public static array $charges = [];

    public function __construct(
        private readonly GatewayConfig $config,
        private readonly Repository $appConfig,
    ) {}

    public function key(): string
    {
        return $this->config->key;
    }

    public function name(): string
    {
        return $this->config->name;
    }

    public function isAvailable(): bool
    {
        return $this->config->enabled && filled($this->config->credential('api_key'));
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'starts_with:tok_'],
            'memo' => 'sometimes|string|max:50',
        ];
    }

    public function charge(ChargeRequest $request): ChargeResult
    {
        self::$charges[] = $request;

        if ($request->detail('token') === self::DECLINE_TOKEN) {
            return ChargeResult::failed('fake_declined', 'The fake gateway declined.');
        }

        return ChargeResult::successful('fake_'.$request->paymentId, [
            'currency_seen' => $request->currency,
            'app_name' => $this->appConfig->get('app.name'),
        ]);
    }
}
