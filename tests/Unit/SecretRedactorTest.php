<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Logging\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function testKnownSecretsAreRemovedFromDiagnosticText(): void
    {
        $direct = self::testKey('direct');
        $fileBacked = self::testKey('file');
        $text = sprintf('direct=%s file=%s repeated=%s', $direct, $fileBacked, $direct);

        $redacted = (new SecretRedactor())->redact($text, [$direct, $fileBacked]);

        self::assertStringNotContainsString($direct, $redacted);
        self::assertStringNotContainsString($fileBacked, $redacted);
        self::assertSame('direct=[REDACTED] file=[REDACTED] repeated=[REDACTED]', $redacted);
    }

    public function testEmptySecretDoesNotRewriteEveryCharacterBoundary(): void
    {
        self::assertSame('safe message', (new SecretRedactor())->redact('safe message', ['']));
    }

    private static function testKey(string $suffix): string
    {
        return 'rk_' . 'test_' . 'unit-' . $suffix;
    }
}
