<?php

namespace Modules\Payments\Console;

use Illuminate\Console\Command;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;
use Modules\Payments\Services\GatewayConfigResolver;
use Modules\Payments\Services\GatewayRegistry;

/**
 * Write a gateway's database override — switch it on or off, rotate a key —
 * without touching .env or deploying.
 *
 *     php artisan payments:gateway paypal --disable
 *     php artisan payments:gateway credit_card --credential=secret_key=sk_live_...
 *     php artisan payments:gateway bank_transfer --setting=iban=DE89370400440532013000
 *     php artisan payments:gateway paypal --reset
 */
class ConfigureGatewayCommand extends Command
{
    protected $signature = 'payments:gateway
        {key : The gateway key, e.g. credit_card}
        {--enable : Switch the gateway on}
        {--disable : Switch the gateway off}
        {--credential=* : name=value, stored encrypted}
        {--setting=* : name=value}
        {--reset : Delete the override so config/.env applies again}';

    protected $description = 'Override a payment gateway\'s configuration in the database';

    public function handle(
        PaymentGatewaySettingRepositoryInterface $settings,
        GatewayRegistry $gateways,
        GatewayConfigResolver $configs,
    ): int {
        $key = (string) $this->argument('key');

        if (! $gateways->has($key)) {
            $this->error("No gateway is configured under [{$key}]. Known: ".implode(', ', array_keys($gateways->all())));

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $settings->forget($key);
            $configs->flush();

            $this->info(sprintf('Gateway [%s] override removed; config/.env applies (%s).', $key, $configs->find($key)?->enabled ? 'enabled' : 'disabled'));

            return self::SUCCESS;
        }

        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Pass --enable or --disable, not both.');

            return self::FAILURE;
        }

        $enabled = match (true) {
            (bool) $this->option('enable') => true,
            (bool) $this->option('disable') => false,
            default => null,
        };

        $existing = $settings->allKeyed()[$key] ?? null;

        $settings->upsert(
            $key,
            $enabled,
            $this->pairs('credential', (array) ($existing?->credentials ?? [])),
            $this->pairs('setting', (array) ($existing?->settings ?? [])),
        );

        $configs->flush();

        $config = $configs->find($key);
        $this->info(sprintf('Gateway [%s] is now %s.', $key, $config?->enabled ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>|null null when the option was not passed
     */
    private function pairs(string $option, array $current): ?array
    {
        $values = (array) $this->option($option);

        if ($values === []) {
            return null;
        }

        foreach ($values as $pair) {
            [$name, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $current[trim($name)] = $value;
        }

        return $current;
    }
}
