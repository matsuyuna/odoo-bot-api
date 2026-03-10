<?php

namespace Tests\Unit;

use App\Support\VenezuelanPhoneFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VenezuelanPhoneFormatterTest extends TestCase
{
    #[DataProvider('watiProvider')]
    public function test_to_wati(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, VenezuelanPhoneFormatter::toWati($input));
    }

    public static function watiProvider(): array
    {
        return [
            ['04244162964', '+584244162964'],
            ['+58 424-416-2964', '+584244162964'],
            ['584244162964', '+584244162964'],
            ['4244162964', '+584244162964'],
            ['abc', null],
            ['+15551234567', null],
        ];
    }

    #[DataProvider('odooProvider')]
    public function test_to_odoo(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, VenezuelanPhoneFormatter::toOdoo($input));
    }

    public static function odooProvider(): array
    {
        return [
            ['+584244162964', '04244162964'],
            ['584244162964', '04244162964'],
            ['04244162964', '04244162964'],
            ['4244162964', '04244162964'],
            ['abc', null],
            ['+15551234567', null],
        ];
    }
}
