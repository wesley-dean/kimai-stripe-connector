<?php

/**
 * @file
 * @brief Defines failures caused by invalid connector configuration.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration;

use RuntimeException;

/**
 * @brief Reports configuration that cannot be used safely.
 *
 * @details
 * This exception is reserved for operator-controlled configuration failures.
 * Callers should map it to the CONFIGURATION_ERROR operator result rather than
 * treating it as an unexpected programming failure.
 */
final class ConfigurationException extends RuntimeException
{
}
