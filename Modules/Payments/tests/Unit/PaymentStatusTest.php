<?php

namespace Modules\Payments\Tests\Unit;

use Modules\Payments\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    /** @return array<string, array{0: PaymentStatus, 1: bool}> */
    public static function statuses(): array
    {
        return [
            'successful blocks: the order is paid' => [PaymentStatus::Successful, true],
            'pending blocks: the money may still arrive' => [PaymentStatus::Pending, true],
            'failed does not block: retrying is the point' => [PaymentStatus::Failed, false],
        ];
    }

    #[DataProvider('statuses')]
    public function test_blocks_new_payment(PaymentStatus $status, bool $blocks): void
    {
        $this->assertSame($blocks, $status->blocksNewPayment());
    }

    public function test_values_lists_every_status(): void
    {
        $this->assertSame(['pending', 'successful', 'failed'], PaymentStatus::values());
    }
}
