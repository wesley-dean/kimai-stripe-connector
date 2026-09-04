<?php

/**
 * @file
 * @brief Represents a Stripe/network failure at the Payment Link boundary.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\PaymentLink;

use RuntimeException;
use Throwable;

/**
 * @brief Remote failure with explicit uncertainty about whether a create committed.
 */
final class PaymentLinkGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $commitMayHaveOccurred = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function commitMayHaveOccurred(): bool
    {
        return $this->commitMayHaveOccurred;
    }
}
