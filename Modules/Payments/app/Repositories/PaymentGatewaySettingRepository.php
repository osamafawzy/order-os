<?php

namespace Modules\Payments\Repositories;

use Modules\Core\Repositories\BaseRepository;
use Modules\Payments\Models\PaymentGatewaySetting;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;

class PaymentGatewaySettingRepository extends BaseRepository implements PaymentGatewaySettingRepositoryInterface
{
    protected string $model = PaymentGatewaySetting::class;

    public function allKeyed(): array
    {
        return $this->query()->get()->keyBy('key')->all();
    }

    public function upsert(string $key, ?bool $enabled, ?array $credentials = null, ?array $settings = null): PaymentGatewaySetting
    {
        /** @var PaymentGatewaySetting */
        return $this->query()->updateOrCreate(['key' => $key], array_filter([
            'enabled' => $enabled,
            'credentials' => $credentials,
            'settings' => $settings,
        ], static fn ($value): bool => $value !== null));
    }

    public function forget(string $key): void
    {
        $this->query()->where('key', $key)->delete();
    }
}
