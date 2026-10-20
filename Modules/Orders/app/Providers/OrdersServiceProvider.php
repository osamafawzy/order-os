<?php

namespace Modules\Orders\Providers;

use Modules\Core\Providers\ModuleServiceProvider;
use Modules\Orders\Contracts\OrderPaymentInspector;
use Modules\Orders\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Orders\Repositories\OrderRepository;
use Modules\Orders\Support\NullOrderPaymentInspector;

class OrdersServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Orders';

    protected string $nameLower = 'orders';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(OrderRepositoryInterface::class, OrderRepository::class);

        // Rebound by the Payments module. Until then, and wherever that
        // module is switched off, no order has payments.
        $this->app->bindIf(OrderPaymentInspector::class, NullOrderPaymentInspector::class);
    }
}
