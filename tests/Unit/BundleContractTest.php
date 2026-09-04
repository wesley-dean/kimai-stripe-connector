<?php

declare(strict_types=1);

namespace KimaiStripeConnectorTests\Unit;

use App\Plugin\PluginInterface;
use KimaiPlugin\KimaiStripeConnectorBundle\KimaiStripeConnectorBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BundleContractTest extends TestCase
{
    public function testBundleUsesSupportedKimaiPluginShape(): void
    {
        $bundle = new KimaiStripeConnectorBundle();

        self::assertInstanceOf(Bundle::class, $bundle);
        self::assertInstanceOf(PluginInterface::class, $bundle);
    }
}
