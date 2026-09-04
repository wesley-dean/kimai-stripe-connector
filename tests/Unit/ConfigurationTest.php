<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\ConfigurationException;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\EnvironmentConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private const INSTANCE_ID = '018f6f56-8f4e-7c73-9a62-2f5e4f51b9e1';

    public function testDisabledConnectorNeedsNoCredential(): void
    {
        $configuration = (new EnvironmentConfigurationLoader())->load([]);

        self::assertFalse($configuration->enabled());
        self::assertFalse($configuration->paymentLinksEnabled());
        self::assertFalse($configuration->reconciliationEnabled());
        self::assertSame(
            EnvironmentConfigurationLoader::DEFAULT_CLEANUP_GRACE_DAYS,
            $configuration->cleanupGraceDays(),
        );
        self::assertNull($configuration->instanceId());
        self::assertNull($configuration->stripeCredential());
    }

    public function testEnabledPaymentLinksAcceptDirectRestrictedCredential(): void
    {
        $configuration = (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED' => '1',
            'KIMAI_STRIPE_CONNECTOR_INSTANCE_ID' => self::INSTANCE_ID,
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => self::testKey(),
        ]);

        self::assertTrue($configuration->enabled());
        self::assertTrue($configuration->paymentLinksEnabled());
        self::assertSame(self::INSTANCE_ID, $configuration->instanceId()?->value());
        self::assertSame(CredentialSource::DIRECT, $configuration->stripeCredential()?->source());
    }

    public function testProcessEnvironmentUsesSameParserAsExplicitMap(): void
    {
        $values = [
            'KIMAI_STRIPE_CONNECTOR_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_INSTANCE_ID' => self::INSTANCE_ID,
            'KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS' => '14',
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => self::testKey(),
        ];
        $original = [];

        foreach ($values as $name => $value) {
            $original[$name] = getenv($name);
            self::assertTrue(putenv(sprintf('%s=%s', $name, $value)));
        }

        try {
            $loader = new EnvironmentConfigurationLoader();
            $fromProcess = $loader->loadFromEnvironment();
            $fromMap = $loader->load($values);

            self::assertSame($fromMap->safeLogContext(), $fromProcess->safeLogContext());
            self::assertSame(
                $fromMap->stripeCredential()?->value(),
                $fromProcess->stripeCredential()?->value(),
            );
        } finally {
            foreach ($original as $name => $value) {
                $assignment = $value === false ? $name : sprintf('%s=%s', $name, $value);
                self::assertTrue(putenv($assignment));
            }
        }
    }

    public function testEnabledReconciliationAcceptsTrimmedFileCredential(): void
    {
        $loader = new EnvironmentConfigurationLoader(
            static fn (string $path): string => $path === '/mounted/stripe-key'
                ? "  " . self::testKey() . "\n"
                : ''
        );

        $configuration = $loader->load([
            'KIMAI_STRIPE_CONNECTOR_ENABLED' => 'TRUE',
            'KIMAI_STRIPE_CONNECTOR_RECONCILIATION_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_INSTANCE_ID' => self::INSTANCE_ID,
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE' => '/mounted/stripe-key',
        ]);

        self::assertSame(self::testKey(), $configuration->stripeCredential()?->value());
        self::assertSame(CredentialSource::FILE, $configuration->stripeCredential()?->source());
    }

    public function testBothCredentialFormsAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => self::testKey(),
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE' => '/mounted/stripe-key',
        ]);
    }

    public function testMissingSecretFileIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE' => '/path/that/does/not/exist',
        ]);
    }

    public function testUnreadableSecretFileFailureIsPreserved(): void
    {
        $loader = new EnvironmentConfigurationLoader(
            static fn (string $path): string => throw new ConfigurationException(
                sprintf('Unreadable secret file: %s', $path)
            )
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unreadable secret file');

        $loader->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE' => '/mounted/unreadable',
        ]);
    }

    public function testBlankSecretFileIsRejected(): void
    {
        $loader = new EnvironmentConfigurationLoader(static fn (string $path): string => " \n\t ");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('blank after trimming');

        $loader->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY_FILE' => '/mounted/blank',
        ]);
    }

    #[DataProvider('invalidIdentityProvider')]
    public function testActiveCapabilityRequiresValidInstanceIdentity(?string $instanceId): void
    {
        $environment = [
            'KIMAI_STRIPE_CONNECTOR_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_PAYMENT_LINKS_ENABLED' => 'true',
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => self::testKey(),
        ];

        if ($instanceId !== null) {
            $environment['KIMAI_STRIPE_CONNECTOR_INSTANCE_ID'] = $instanceId;
        }

        $this->expectException(ConfigurationException::class);
        (new EnvironmentConfigurationLoader())->load($environment);
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'malformed' => ['not-a-uuid'];
        yield 'nil' => ['00000000-0000-0000-0000-000000000000'];
    }

    #[DataProvider('invalidBooleanProvider')]
    public function testMalformedBooleanIsRejected(string $value): void
    {
        $this->expectException(ConfigurationException::class);

        (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_ENABLED' => $value,
        ]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidBooleanProvider(): iterable
    {
        yield 'yes' => ['yes'];
        yield 'blank' => [''];
        yield 'whitespace' => [' true '];
    }

    #[DataProvider('invalidGraceProvider')]
    public function testInvalidCleanupGracePeriodIsRejected(string $value): void
    {
        $this->expectException(ConfigurationException::class);

        (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_CLEANUP_GRACE_DAYS' => $value,
        ]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidGraceProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'fraction' => ['1.5'];
        yield 'leading-zero' => ['07'];
        yield 'too-large' => ['3651'];
    }

    public function testBroadStripeSecretKeyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('restricted API key');

        (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => 'sk_' . 'test_' . 'unit-placeholder',
        ]);
    }

    public function testSafeLogContextContainsNoCredentialValue(): void
    {
        $configuration = (new EnvironmentConfigurationLoader())->load([
            'KIMAI_STRIPE_CONNECTOR_STRIPE_API_KEY' => self::testKey(),
        ]);

        $encoded = json_encode($configuration->safeLogContext(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::testKey(), $encoded);
        self::assertStringContainsString('direct', $encoded);
    }

    private static function testKey(): string
    {
        return 'rk_' . 'test_' . 'unit-placeholder';
    }
}
