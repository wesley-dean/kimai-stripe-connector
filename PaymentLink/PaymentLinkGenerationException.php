<?php

/**
 * @file
 * @brief Represents an actionable failure while preparing a Kimai invoice Payment Link.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use RuntimeException;

/**
 * @brief Safe exception used to stop invoice rendering when payment automation cannot be proven safe.
 */
final class PaymentLinkGenerationException extends RuntimeException
{
}
