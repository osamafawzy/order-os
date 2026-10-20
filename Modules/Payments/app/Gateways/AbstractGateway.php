<?php

namespace Modules\Payments\Gateways;

use Illuminate\Support\Str;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Support\GatewayConfig;

/**
 * The parts every gateway shares: identity and availability from its config.
 *
 * Extending this is a convenience, not a requirement — the registry only
 * insists on the PaymentGateway interface.
 */
abstract class AbstractGateway implements PaymentGateway
{
    public function __construct(
        protected readonly GatewayConfig $config,
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
        if (! $this->config->enabled) {
            return false;
        }

        foreach ($this->requiredCredentials() as $credential) {
            if (blank($this->config->credential($credential))) {
                return false;
            }
        }

        foreach ($this->requiredSettings() as $setting) {
            if (blank($this->config->setting($setting))) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * Credential names that must be present for the gateway to be usable.
     *
     * @return array<int, string>
     */
    protected function requiredCredentials(): array
    {
        return [];
    }

    /** @return array<int, string> */
    protected function requiredSettings(): array
    {
        return [];
    }

    /** A gateway-style reference: prefix plus 24 random characters. */
    protected function reference(string $prefix): string
    {
        return $prefix.Str::upper(Str::random(24));
    }
}
