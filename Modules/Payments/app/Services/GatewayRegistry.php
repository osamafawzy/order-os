<?php

namespace Modules\Payments\Services;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Exceptions\PaymentException;

/**
 * Turns a method key into a gateway — the strategy selector.
 *
 * Gateways are built through the container, with their own GatewayConfig
 * passed in by name. Anything else a gateway's constructor asks for (an HTTP
 * client, a logger, a vendor SDK) is injected automatically, so a new
 * gateway never needs a factory or a change here.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $instances = [];

    public function __construct(
        private readonly Container $container,
        private readonly GatewayConfigResolver $configs,
    ) {}

    public function has(string $key): bool
    {
        return $this->configs->find($key) !== null;
    }

    /**
     * @throws PaymentException when no gateway is configured under this key
     */
    public function get(string $key): PaymentGateway
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $config = $this->configs->find($key) ?? throw PaymentException::unsupportedMethod($key, $this->availableKeys());

        $gateway = $this->container->make($config->driver, ['config' => $config]);

        // A misconfigured driver is a deploy bug, not a client error, so it
        // fails loudly rather than as a 422 the client cannot fix.
        if (! $gateway instanceof PaymentGateway) {
            throw new LogicException(sprintf(
                'Payment gateway [%s] is configured with driver [%s], which does not implement %s.',
                $key,
                $config->driver,
                PaymentGateway::class,
            ));
        }

        return $this->instances[$key] = $gateway;
    }

    /**
     * The gateway for a payment, only if a client may use it right now.
     *
     * @throws PaymentException
     */
    public function available(string $key): PaymentGateway
    {
        $gateway = $this->get($key);

        if (! $gateway->isAvailable()) {
            throw PaymentException::methodUnavailable($key, $this->availableKeys());
        }

        return $gateway;
    }

    /** @return array<string, PaymentGateway> every configured gateway, on or off */
    public function all(): array
    {
        $gateways = [];

        foreach (array_keys($this->configs->all()) as $key) {
            $gateways[$key] = $this->get($key);
        }

        return $gateways;
    }

    /** @return array<int, string> */
    public function availableKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->configs->all()) as $key) {
            if ($this->get($key)->isAvailable()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
