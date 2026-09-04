<?php

/**
 * @file
 * @brief Carries operator-safe Stripe cleanup failure classification.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Cleanup;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use RuntimeException;
use Throwable;

/**
 * @brief Modeled Stripe cleanup failure with ADR-013 classification.
 */
final class CleanupGatewayException extends RuntimeException
{
    public function __construct(
        private readonly OperatorResult $result,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }
}
