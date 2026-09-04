<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\CredentialSource;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration\StripeCredential;
use KimaiPlugin\KimaiStripeConnectorBundle\Common\Stripe\StripeClientFactory;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

final class StripeClientFactoryTest extends TestCase
{
    public function testFactoryConstructsClientWithoutApiVersionOverride(): void
    {
        $credential = new StripeCredential(self::testKey(), CredentialSource::DIRECT);
        $client = (new StripeClientFactory())->create($credential);

        self::assertInstanceOf(StripeClient::class, $client);
        self::assertSame(self::testKey(), $client->getApiKey());
        self::assertSame(ApiVersion::CURRENT, $client->getStripeVersion());
    }

    public function testFactoryProvidesStableApplicationIdentification(): void
    {
        self::assertSame([
            'name' => 'kimai-stripe-connector',
            'url' => 'https://github.com/wesley-dean/kimai-stripe-connector',
        ], (new StripeClientFactory())->applicationInfo());
    }

    private static function testKey(): string
    {
        return 'rk_' . 'test_' . 'unit-placeholder';
    }
}
