<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            'local with leading zero' => ['076123456', '23276123456'],
            'local without zero' => ['76123456', '23276123456'],
            'international with plus and spaces' => ['+232 76 123 456', '23276123456'],
            'international digits only' => ['23276123456', '23276123456'],
            '00 prefix' => ['00232 76 123456', '23276123456'],
            'dashes and spaces' => ['076-123 456', '23276123456'],
            'plus 232 then leading zero' => ['+232 076 123 456', '23276123456'],
            'surrounding whitespace' => ["  076123456\n", '23276123456'],
            'afrimoney style' => ['030 555 123', '23230555123'],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_it_normalises_to_232_plus_8_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
        $this->assertTrue(PhoneNumber::isValid($input));
    }

    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'letters' => ['abc'],
            'too short' => ['7612345'],
            'too long' => ['0761234567'],
            'wrong country code' => ['+44 7911 123456'],
            'country code only' => ['232'],
            'subscriber starting with zero' => ['2320123456789'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_it_rejects_invalid_numbers(string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    public function test_null_is_invalid(): void
    {
        $this->assertNull(PhoneNumber::normalize(null));
    }

    public function test_it_displays_as_local_format(): void
    {
        $this->assertSame('076 123 456', PhoneNumber::display('23276123456'));
        $this->assertSame('076 123 456', PhoneNumber::display('+232 76 123 456'));
    }

    public function test_display_returns_unparseable_input_unchanged(): void
    {
        $this->assertSame('not a phone', PhoneNumber::display('not a phone'));
        $this->assertSame('', PhoneNumber::display(null));
    }

    public function test_normalise_is_idempotent(): void
    {
        $once = PhoneNumber::normalize('076123456');

        $this->assertSame($once, PhoneNumber::normalize($once));
    }
}
