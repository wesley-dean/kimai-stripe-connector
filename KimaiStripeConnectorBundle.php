<?php

declare(strict_types=1);

/**
 * @file
 * @brief Kimai plugin bundle entrypoint for the Stripe connector.
 *
 * @details
 * This bundle is intentionally inert at the implementation-scaffold stage.  It
 * establishes the supported Kimai plugin lifecycle and loads the plugin-local
 * Composer dependency tree without introducing Stripe mutations, reconciliation,
 * or other billing behavior.
 */

namespace KimaiPlugin\KimaiStripeConnectorBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

/**
 * @brief Registers the connector as a normal Kimai plugin bundle.
 *
 * @details
 * Kimai discovers the bundle through its `KimaiPlugin` autoload namespace.  The
 * bundle deliberately performs no payment or invoice mutation merely by being
 * installed or loaded.
 *
 * @par Invariant
 * Installing, disabling, or removing this bundle must not require changes to
 * Kimai core, the Kimai root Composer files, or `config/bundles.php`.
 */
final class KimaiStripeConnectorBundle extends Bundle implements PluginInterface
{
}
