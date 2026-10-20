<?php

namespace Modules\Payments\Services;

use Illuminate\Database\QueryException;
use Modules\Payments\Models\PaymentGatewaySetting;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;
use Modules\Payments\Support\GatewayConfig;

/**
 * Builds each gateway's effective configuration: config/.env first, then the
 * payment_gateway_settings row for that key laid over the top.
 *
 * Resolved once per request (the resolver is scoped), so listing gateways and
 * then charging one does not read the settings table twice.
 */
class GatewayConfigResolver
{
    /** @var array<string, GatewayConfig>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly PaymentGatewaySettingRepositoryInterface $settings,
    ) {}

    public function find(string $key): ?GatewayConfig
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, GatewayConfig> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $overrides = $this->overrides();
        $resolved = [];

        foreach ((array) config('payments.gateways', []) as $key => $entry) {
            if (! is_array($entry) || ! isset($entry['driver'])) {
                continue;
            }

            $override = $overrides[$key] ?? null;

            $resolved[$key] = new GatewayConfig(
                key: (string) $key,
                driver: $entry['driver'],
                name: (string) ($entry['name'] ?? $key),
                enabled: $override?->enabled ?? (bool) ($entry['enabled'] ?? false),
                // Per-key merge: a row that rotates only the secret keeps the
                // public key from .env.
                credentials: array_replace((array) ($entry['credentials'] ?? []), (array) ($override?->credentials ?? [])),
                settings: array_replace((array) ($entry['settings'] ?? []), (array) ($override?->settings ?? [])),
            );
        }

        return $this->resolved = $resolved;
    }

    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * @return array<string, PaymentGatewaySetting>
     */
    private function overrides(): array
    {
        try {
            return $this->settings->allKeyed();
        } catch (QueryException) {
            // Before migrations have run — `artisan route:list` on a fresh
            // checkout — the table does not exist yet. Config alone is a
            // complete answer.
            return [];
        }
    }
}
