<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Test-only marker matching the Kimai plugin interface used by the bundle.
 *
 * Real Kimai integration tests use Kimai's actual interface.  This stub exists
 * only so standalone unit/static-analysis jobs can load the inert bundle without
 * installing the entire Kimai application as a development dependency.
 */
interface PluginInterface
{
}
