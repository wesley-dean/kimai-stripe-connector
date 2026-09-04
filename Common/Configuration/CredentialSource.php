<?php

/**
 * @file
 * @brief Identifies how the Stripe credential reached the connector.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Common\Configuration;

/**
 * @brief Safe, non-secret Stripe credential source identifiers.
 */
enum CredentialSource: string
{
    case DIRECT = 'direct';
    case FILE = 'file';
}
