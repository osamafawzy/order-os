<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{0: int|float|string|null, 1: int}> */
    public static function toMinorCases(): array
    {
        return [
            'integer' => [12, 1200],
            'zero' => [0, 0],
            'null is zero' => [null, 0],
            'two-decimal string' => ['19.99', 1999],
            'one-decimal string' => ['5.5', 550],
            'float' => [25.5, 2550],
            'float that is not exact in binary' => [0.29, 29],
            'largest order line price' => ['999999.99', 99999999],
            'half a cent rounds up' => ['1.005', 101],
            'below half a cent rounds down' => ['1.004', 100],
        ];
    }

    #[DataProvider('toMinorCases')]
    public function test_to_minor_converts_to_integer_cents(int|float|string|null $amount, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($amount));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function fromMinorCases(): array
    {
        return [
            'zero' => [0, '0.00'],
            'single cent' => [1, '0.01'],
            'whole amount keeps two places' => [5100, '51.00'],
            'no thousands separator' => [123456789, '1234567.89'],
            'negative' => [-250, '-2.50'],
        ];
    }

    #[DataProvider('fromMinorCases')]
    public function test_from_minor_formats_a_two_decimal_string(int $minor, string $expected): void
    {
        $this->assertSame($expected, Money::fromMinor($minor));
    }

    public function test_summing_in_minor_units_avoids_floating_point_drift(): void
    {
        // The classic: in floating point this is 0.30000000000000004.
        $this->assertNotSame(0.3, 0.1 + 0.2);

        $this->assertSame('0.30', Money::fromMinor(Money::toMinor('0.1') + Money::toMinor('0.2')));
    }

    public function test_repeated_addition_stays_exact(): void
    {
        $minor = 0;

        for ($i = 0; $i < 1000; $i++) {
            $minor += Money::toMinor('0.1');
        }

        $this->assertSame('100.00', Money::fromMinor($minor));
    }

    /** @return array<string, array{0: int|float|string|null, 1: string}> */
    public static function normalizeCases(): array
    {
        return [
            'integer' => [7, '7.00'],
            'one decimal' => ['7.5', '7.50'],
            'already normalized' => ['7.25', '7.25'],
            'float sum' => [0.1 + 0.2, '0.30'],
            'null' => [null, '0.00'],
            'leading zeros' => ['007.10', '7.10'],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize_returns_the_canonical_string(int|float|string|null $amount, string $expected): void
    {
        $this->assertSame($expected, Money::normalize($amount));
    }

    public function test_round_trip_is_lossless_for_every_cent_value(): void
    {
        foreach ([1, 10, 99, 100, 101, 1999, 2550, 99999999] as $minor) {
            $this->assertSame($minor, Money::toMinor(Money::fromMinor($minor)));
        }
    }
}
