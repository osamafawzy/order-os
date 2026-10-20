<?php

namespace Modules\Payments\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Modules\Payments\Rules\LuhnNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LuhnNumberTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function validNumbers(): array
    {
        return [
            'visa test card' => ['4242424242424242'],
            'visa declined test card' => ['4000000000000002'],
            'visa insufficient funds test card' => ['4000000000009995'],
            'mastercard' => ['5555555555554444'],
            'mastercard 2-series' => ['2223003122003222'],
            'amex (15 digits)' => ['378282246310005'],
            'discover' => ['6011111111111117'],
            'diners (14 digits)' => ['30569309025904'],
            'with spaces' => ['4242 4242 4242 4242'],
            'all zeros' => ['0000000000000000'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_valid_numbers_pass(string $number): void
    {
        $this->assertTrue(Validator::make(['n' => $number], ['n' => [new LuhnNumber]])->passes());
    }

    /** @return array<string, array{0: string}> */
    public static function invalidNumbers(): array
    {
        return [
            'last digit off by one' => ['4242424242424241'],
            'two digits swapped' => ['4242424242424224'],
            'single digit changed' => ['4000000000000003'],
            'letters only' => ['abcdabcdabcdabcd'],
            'empty after stripping' => ['----'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_fail_with_a_readable_message(string $number): void
    {
        $validator = Validator::make(['n' => $number], ['n' => [new LuhnNumber]]);

        $this->assertTrue($validator->fails());
        $this->assertSame('The card number is not valid.', $validator->errors()->first('n'));
    }

    public function test_passes_works_on_raw_digit_strings(): void
    {
        $this->assertTrue(LuhnNumber::passes('79927398713'));
        $this->assertFalse(LuhnNumber::passes('79927398710'));
    }
}
