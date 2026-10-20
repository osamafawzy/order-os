<?php

namespace Modules\Payments\Support;

use Modules\Payments\Contracts\PaymentGateway;

/**
 * Everything a gateway is told about itself: who it is, whether it is on, and
 * its secrets. Built by GatewayConfigResolver from config/.env, overlaid with
 * the payment_gateway_settings table.
 */
final readonly class GatewayConfig
{
    /**
     * @param  class-string<PaymentGateway>  $driver
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $key,
        public string $driver,
        public string $name,
        public bool $enabled,
        public array $credentials = [],
        public array $settings = [],
    ) {}

    public function credential(string $name): mixed
    {
        return $this->credentials[$name] ?? null;
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->settings[$name] ?? $default;
    }
}
