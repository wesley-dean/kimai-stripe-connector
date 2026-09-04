<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Identity\KimaiInstanceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KimaiInstanceIdTest extends TestCase
{
    public function testCanonicalUuidIsNormalizedToLowercase(): void
    {
        $identity = new KimaiInstanceId('018F6F56-8F4E-7C73-9A62-2F5E4F51B9E1');

        self::assertSame('018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1', $identity->value());
    }

    #[DataProvider('invalidUuidProvider')]
    public function testMalformedUuidIsRejected(string $value): void
    {
        $this->expectException(ConfigurationException::class);

        new KimaiInstanceId($value);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidUuidProvider(): iterable
    {
        yield 'nil' => ['00000000-0000-0000-0000-000000000000'];
        yield 'missing hyphens' => ['018f6f568f4e7c739a622f5e4f51b9e1'];
        yield 'invalid variant' => ['018f6f56-8f4e-7c73-1a62-2f5e4f51b9e1'];
        yield 'invalid version' => ['018f6f56-8f4e-0c73-9a62-2f5e4f51b9e1'];
    }
}
