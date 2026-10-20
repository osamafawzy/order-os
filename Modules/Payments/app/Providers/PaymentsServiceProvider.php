<?php

namespace Modules\Payments\Providers;

use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Orders\Contracts\OrderPaymentInspector;
use Modules\Payments\Console\ConfigureGatewayCommand;
use Modules\Payments\Repositories\Contracts\PaymentGatewaySettingRepositoryInterface;
use Modules\Payments\Repositories\Contracts\PaymentRepositoryInterface;
use Modules\Payments\Repositories\PaymentGatewaySettingRepository;
use Modules\Payments\Repositories\PaymentRepository;
use Modules\Payments\Services\GatewayConfigResolver;
use Modules\Payments\Services\GatewayRegistry;
use Modules\Payments\Support\PaymentOrderInspector;

class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Payments';

    protected string $nameLower = 'payments';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        ConfigureGatewayCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(PaymentGatewaySettingRepositoryInterface::class, PaymentGatewaySettingRepository::class);

        // Scoped: one resolved config and one set of gateway instances per
        // request (or queued job), never carried over to the next one.
        $this->app->scoped(GatewayConfigResolver::class);
        $this->app->scoped(GatewayRegistry::class);

        // Replaces the Orders module's null default.
        $this->app->bind(OrderPaymentInspector::class, PaymentOrderInspector::class);
    }
}
