<?php

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payments\Http\Resources\PaymentGatewayResource;
use Modules\Payments\Services\GatewayRegistry;

class PaymentGatewayController extends ApiController
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return PaymentGatewayResource::collection(array_values($this->gateways->all()));
    }
}
