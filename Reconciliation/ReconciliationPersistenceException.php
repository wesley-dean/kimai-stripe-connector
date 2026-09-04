<?php

/**
 * @file
 * @brief Carries stable operator classification for Kimai persistence failures.
 */

declare(strict_types=1);

namespace KimaiPlugin\KimaiStripeConnectorBundle\Reconciliation;

use KimaiPlugin\KimaiStripeConnectorBundle\Common\Result\OperatorResult;
use RuntimeException;
use Throwable;

/**
 * @brief Kimai lookup or persistence failure with ADR-013 classification.
 */
final class ReconciliationPersistenceException extends RuntimeException
{
    public function __construct(
        private readonly OperatorResult $result,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function result(): OperatorResult
    {
        return $this->result;
    }
}
