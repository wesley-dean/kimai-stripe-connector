<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use InvalidArgumentException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('exactAmountProvider')]
    public function testExactTwoDecimalConversion(string $amount, string $currency, int $expected): void
    {
        $money = Money::fromDecimal($amount, $currency);

        self::assertSame($expected, $money->minorUnits());
        self::assertSame(strtolower($currency), $money->currency()->value());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function exactAmountProvider(): iterable
    {
        yield 'whole dollars' => ['875', 'USD', 87500];
        yield 'one decimal place' => ['875.1', 'usd', 87510];
        yield 'two decimal places' => ['875.10', 'usd', 87510];
        yield 'one cent boundary' => ['0.01', 'eur', 1];
        yield 'leading fractional zero' => ['12.05', 'gbp', 1205];
    }

    #[DataProvider('invalidAmountProvider')]
    public function testInvalidAmountPolicyRejectsUnsafeValues(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal($amount, 'usd');
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidAmountProvider(): iterable
    {
        yield 'zero whole' => ['0'];
        yield 'zero decimal' => ['0.00'];
        yield 'negative' => ['-1.00'];
        yield 'third decimal even when zero' => ['10.000'];
        yield 'rounding would be required' => ['10.005'];
        yield 'leading plus' => ['+1.00'];
        yield 'scientific notation' => ['1e2'];
        yield 'comma grouping' => ['1,000.00'];
        yield 'leading whitespace' => [' 1.00'];
        yield 'trailing whitespace' => ['1.00 '];
        yield 'huge amount' => ['999999999999999999999999999999999999.99'];
    }

    #[DataProvider('unsupportedCurrencyProvider')]
    public function testUnsupportedCurrencySemanticsFailClosed(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('10.00', $currency);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unsupportedCurrencyProvider(): iterable
    {
        yield 'zero-decimal JPY' => ['jpy'];
        yield 'zero-decimal KRW' => ['krw'];
        yield 'special-case ISK' => ['isk'];
        yield 'special-case HUF' => ['huf'];
        yield 'special-case TWD' => ['twd'];
        yield 'special-case UGX' => ['ugx'];
        yield 'unknown code' => ['zzz'];
        yield 'malformed code' => ['us'];
    }

    public function testMinorUnitConstructionUsesSameCurrencyPolicy(): void
    {
        $money = Money::fromMinorUnits(12345, 'CAD');

        self::assertSame(12345, $money->minorUnits());
        self::assertSame('cad', $money->currency()->value());
    }
}
