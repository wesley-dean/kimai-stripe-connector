<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PackageMetadataTest extends TestCase
{
    public function testComposerMetadataMatchesInitialCompatibilityContract(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame('kimai-plugin', $composer['type']);
        self::assertSame('>=8.2 <8.6', $composer['require']['php']);
        self::assertSame('^21.3.1', $composer['require']['stripe/stripe-php']);
        self::assertSame(26500, $composer['extra']['kimai']['require']);
        self::assertSame('Kimai Stripe Connector', $composer['extra']['kimai']['name']);
    }
}
