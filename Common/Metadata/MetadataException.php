<?php

/**
 * @file
 * @brief Defines failures caused by invalid connector metadata.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Metadata;

use RuntimeException;

/**
 * @brief Reports Stripe metadata that cannot be interpreted safely.
 */
final class MetadataException extends RuntimeException
{
}
