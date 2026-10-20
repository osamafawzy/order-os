<?php

namespace Modules\Payments\Tests\Support;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Exceptions\GatewayUnreachable;
use Modules\Payments\Gateways\AbstractGateway;

/**
 * A gateway whose vendor cannot be reached at all: every charge is refused
 * before it is sent, so no money can have moved.
 */
final class ExplodingGateway extends AbstractGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        throw new GatewayUnreachable('Connection to the payment provider was refused.');
    }
}
