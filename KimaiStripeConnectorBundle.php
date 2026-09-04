<?php

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

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @brief Registers the connector as a normal Kimai plugin bundle.
 *
 * @details
 * Kimai discovers the bundle through its `KimaiPlugin` autoload namespace.  The
 * bundle loads its self-contained Composer dependency graph when Kimai constructs
 * the bundle, before connector services are consumed.  No payment or invoice
 * mutation occurs merely because the bundle is installed or loaded.
 *
 * @par Invariant
 * Installing, disabling, or removing this bundle must not require changes to
 * Kimai core, the Kimai root Composer files, or `config/bundles.php`.
 */
final class KimaiStripeConnectorBundle extends Bundle implements PluginInterface
{
    /**
     * @brief Initialize the plugin-local Composer autoloader when present.
     *
     * @details
     * Release artifacts carry production dependencies under the bundle-local
     * `vendor/` directory.  Loading that autoloader during bundle construction
     * keeps dependency bootstrap inside the supported plugin lifecycle and avoids
     * executable side effects at PHP file scope.
     *
     * The file is optional so source-oriented tooling can inspect the bundle
     * before dependencies are installed.  Real Kimai E2E and release validation
     * require the self-contained dependency tree.
     */
    public function __construct()
    {
        $autoload = __DIR__ . '/vendor/autoload.php';

        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
}
