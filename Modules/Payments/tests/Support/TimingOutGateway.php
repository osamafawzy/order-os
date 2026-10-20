<?php

namespace Modules\Payments\Tests\Support;

use Modules\Payments\DTOs\ChargeRequest;
use Modules\Payments\DTOs\ChargeResult;
use Modules\Payments\Gateways\AbstractGateway;
use RuntimeException;

/**
 * A gateway that sent the charge and never heard back: the vendor may or may
 * not have taken the money.
 */
final class TimingOutGateway extends AbstractGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        throw new RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds.');
    }
}
