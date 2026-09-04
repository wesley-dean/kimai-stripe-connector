<?php

declare(strict_types=1);

/**
 * @file
 * @brief Loads the connector's Symfony service configuration.
 */

namespace KimaiPlugin\KimaiStripeConnectorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @brief Loads services for the Kimai Stripe Connector bundle.
 *
 * @details
 * The initial service configuration is intentionally empty beyond shared service
 * defaults.  Feature services will be added only alongside their implementation
 * and tests.
 */
final class KimaiStripeConnectorExtension extends Extension
{
    /**
     * @brief Load the bundle service definition.
     *
     * @param array<array-key, mixed> $configs Bundle configuration fragments
     *                                      supplied by Symfony.
     * @param ContainerBuilder $container Symfony dependency-injection container.
     *
     * @return void
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.yaml');
    }
}
