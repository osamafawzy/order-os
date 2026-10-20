<?php

namespace Modules\Payments\Repositories\Contracts;

use Modules\Core\Repositories\Contracts\RepositoryInterface;
use Modules\Payments\Models\PaymentGatewaySetting;

interface PaymentGatewaySettingRepositoryInterface extends RepositoryInterface
{
    /** @return array<string, PaymentGatewaySetting> keyed by gateway key */
    public function allKeyed(): array;

    /**
     * @param  array<string, mixed>|null  $credentials
     * @param  array<string, mixed>|null  $settings
     */
    public function upsert(string $key, ?bool $enabled, ?array $credentials = null, ?array $settings = null): PaymentGatewaySetting;

    public function forget(string $key): void;
}
